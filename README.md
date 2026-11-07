# Appointment Booking API

A JSON API for booking appointments at a small business (the seed data is a barber shop). Customers register, look up free time slots, and book, reschedule or cancel appointments. Admins manage the service list, see every appointment, and get basic statistics. Confirmation, cancellation, reschedule and 24-hour reminder emails are sent through the queue.

Built with PHP 8.3, Laravel 13 and Sanctum tokens. MySQL in development, SQLite in memory for tests.

For a walkthrough of the code (request flow, folder map, tables) see [docs/PROJECT_OVERVIEW.md](docs/PROJECT_OVERVIEW.md).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create a MySQL database (default name `appointment_booking`) and set `DB_*` in `.env`. Then:

```bash
php artisan migrate --seed
php artisan serve
```

Run the queue worker in a second terminal, otherwise emails stay in the `jobs` table:

```bash
php artisan queue:work
```

Two commands are scheduled hourly: `appointments:send-reminders` and `appointments:complete-past`. In development run `php artisan schedule:work`; in production add the usual cron entry for `php artisan schedule:run`.

### Environment

The relevant `.env` keys. Everything else is standard Laravel.

| Key | Default | Notes |
|---|---|---|
| `DB_*` | MySQL on localhost | Row locks for double-booking protection need InnoDB |
| `QUEUE_CONNECTION` | `database` | Notifications are queued and sent after the transaction commits |
| `MAIL_MAILER` | `log` | Emails are written to `storage/logs/laravel.log` |
| `APP_TIMEZONE` | `UTC` | Opening hours and all `starts_at` values are interpreted in this timezone |

Business rules live in `config/booking.php`: opening hours, open weekdays, page size, reminder window and the stats window.

### Seeded accounts

| Email | Password | Role |
|---|---|---|
| `admin@example.com` | `password` | admin |
| `customer@example.com` | `password` | customer |

## Authentication

Register or log in to get a token, then send it as `Authorization: Bearer <token>`.

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"customer@example.com","password":"password"}'
```

Tokens expire after 7 days. Login is limited to 5 attempts per minute per email and IP; the rest of the API to 60 requests per minute.

## Endpoints

All routes are under `/api` and, except register and login, require a token.

| Method | Path | Who | What |
|---|---|---|---|
| POST | `/register` | guest | Create a customer account, returns a token |
| POST | `/login` | guest | Returns a token |
| POST | `/logout` | any | Revokes the current token |
| GET | `/me` | any | Current user |
| GET | `/services` | any | List services |
| GET | `/services/{id}` | any | One service |
| POST | `/services` | admin | Create a service |
| PUT/PATCH | `/services/{id}` | admin | Update a service |
| DELETE | `/services/{id}` | admin | Delete a service (409 if it has upcoming confirmed appointments) |
| GET | `/services/{id}/availability?date=YYYY-MM-DD` | any | Free slots for that day |
| GET | `/appointments` | any | Own appointments (admins see all). Filters: `status`, `service_id`, `from`, `to`. Paginated |
| POST | `/appointments` | any | Book a slot |
| GET | `/appointments/{id}` | owner or admin | One appointment |
| PATCH | `/appointments/{id}` | owner or admin | Reschedule to a new `starts_at` |
| POST | `/appointments/{id}/cancel` | owner or admin | Cancel |
| GET | `/admin/stats` | admin | Counts by status, bookings per day, top services, cancellation rate |

### Booking a slot

```bash
curl -X POST http://localhost:8000/api/appointments \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"service_id":1,"starts_at":"2026-11-10 09:45:00"}'
```

`starts_at` must be `Y-m-d H:i:s` or `Y-m-d H:i` in the application timezone, with no offset. The time has to match one of the slots returned by the availability endpoint: a weekday inside opening hours, on the grid defined by the service duration, in the future, and not overlapping another non-cancelled appointment for the same service.

### Error responses

| Status | Meaning |
|---|---|
| 401 | Missing or invalid token |
| 403 | Logged in but not allowed (for example a customer opening someone else's appointment) |
| 404 | Unknown id |
| 409 | Valid request, but the current state forbids it (cancelling an already cancelled appointment, deleting a service with upcoming bookings) |
| 422 | Validation failed, or the requested slot is not available (`errors.starts_at`) |
| 429 | Rate limit hit |

### Appointment statuses

- `confirmed`: set on booking.
- `cancelled`: set by the cancel endpoint.
- `completed`: set by the hourly `appointments:complete-past` command once `ends_at` has passed. Completed appointments can no longer be cancelled or rescheduled.

## Tests

```bash
php artisan test
./vendor/bin/pint
```

The default suite runs on SQLite in memory. One test, `ConcurrentBookingTest`, needs real MySQL row locks: it starts two PHP processes that book the same slot and expects exactly one to win. It is skipped on other drivers, and it also refuses to run unless `DB_DATABASE` ends in `_test`, because it runs `migrate:fresh`.

```bash
DB_CONNECTION=mysql DB_DATABASE=appointment_booking_test php artisan test --group=mysql
```

## How double booking is prevented

`CreateAppointment` and `RescheduleAppointment` run inside a transaction and first lock the service row with `lockForUpdate()`. Two requests for the same service therefore run one after the other, so the second one sees the first one's appointment when it checks for overlaps. See `app/Actions/ListAvailableSlots::isFree()`.
