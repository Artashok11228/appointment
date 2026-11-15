# Appointment Booking API

A JSON API for booking appointments at a small business (the seed data is a barber shop). Customers register, look up free time slots, and book, reschedule or cancel appointments. Admins manage the service list, see every appointment, and get basic statistics. Confirmation, cancellation, reschedule and 24-hour reminder emails are sent through the queue.

Built with PHP 8.3, Laravel 13 and Sanctum tokens. MySQL in development, SQLite in memory for tests.

For a walkthrough of the code (request flow, folder map, tables) see [docs/PROJECT_OVERVIEW.md](docs/PROJECT_OVERVIEW.md).

## API docs (Swagger)

After `php artisan serve`:

- UI: [http://localhost:8000/docs/api](http://localhost:8000/docs/api)
- Spec (OpenAPI): [http://localhost:8000/docs/openapi.yaml](http://localhost:8000/docs/openapi.yaml)

The same contract lives at [`docs/openapi.yaml`](docs/openapi.yaml) for frontend / codegen. In Swagger UI, use **Authorize** with a Bearer token from `POST /api/login`.

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

### Booking rules

The scarce resource is the **service**, not a provider or chair. Overlap is checked per `service_id` only (`Appointment::scopeOverlapping`):

- A service may have at most one non-cancelled appointment in any time range (`confirmed` or `completed`).
- `cancelled` appointments are excluded from the overlap check, so cancelling frees that range for a new booking.
- Different services may be booked at the same wall-clock time; they do not block each other.

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
| 409 | The appointment's **current status** forbids the action (for example cancelling or rescheduling something that is not `confirmed`, or deleting a service that still has upcoming confirmed bookings). Not used for a full slot. |
| 422 | Validation failed, **or the slot is taken / off-grid** (`errors.starts_at`). This is the response for double-booking conflicts. |
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

The default suite runs on SQLite in memory. `ConcurrentBookingTest` (`--group=mysql`) needs real MySQL/InnoDB row locks. It is skipped on other drivers, and it refuses to run unless `DB_DATABASE` ends in `_test`, because it runs `migrate:fresh`.

```bash
DB_CONNECTION=mysql DB_DATABASE=appointment_booking_test php artisan test --group=mysql
```

Expected result: three passing tests. Exactly one booking wins for a contended slot; losers exit as `AppointmentUnavailable` (HTTP 422 via the API), not 500 or deadlock. One of the tests holds the service row lock on purpose so a second process must wait, then lose cleanly — that fails if `lockForUpdate()` on the service is removed.

## How double booking is prevented

Without coordination, two requests can both pass the overlap check before either insert commits, so both would try to take the same slot.

Two layers close that gap:

1. **Service row lock** — `CreateAppointment` and `RescheduleAppointment` run in a transaction and take `lockForUpdate()` on the service row first. A second request for the same service waits, then sees the first booking in `ListAvailableSlots::isFree()` (cancelled rows excluded) and gets a clean **422**. The lock also avoids the InnoDB deadlock you can get when two inserts race without serialising on the service.
2. **Active-slot unique index** — a generated `active_starts_at` column is `NULL` when `status = cancelled`, otherwise `starts_at`, with `UNIQUE(service_id, active_starts_at)`. That is the last line of defence if the app lock is skipped or a write bypasses the Action. A unique violation is caught and turned into the same `AppointmentUnavailable` **422**. After cancel, `active_starts_at` is NULL, so the same `starts_at` can be booked again.

See the [Booking rules](#booking-rules) above for what counts as a conflict.
