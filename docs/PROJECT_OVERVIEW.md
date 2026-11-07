# Project Overview

## What this project is

A JSON API for booking appointments at a small business (the seed data is a barber shop). There is no frontend. A client app (mobile, SPA, Postman) talks to it over `/api/*` and authenticates with a token.

Two kinds of users:

- **Customer**: registers, sees services, checks free time slots, books, reschedules and cancels their own appointments.
- **Admin**: everything a customer can do, plus managing services, seeing every appointment, and reading booking statistics.

Stack: PHP 8.3+, Laravel 13, Laravel Sanctum (API tokens), MySQL in production, SQLite in memory for tests.

## Main features

| Feature | Endpoint |
|---|---|
| Register / login / logout / current user | `POST /api/register`, `POST /api/login`, `POST /api/logout`, `GET /api/me` |
| List and view services | `GET /api/services`, `GET /api/services/{id}` |
| Create, update, delete services (admin only) | `POST`, `PUT/PATCH`, `DELETE /api/services/{id}` |
| Free slots for a service on a day | `GET /api/services/{id}/availability?date=2026-11-10` |
| List appointments (with filters and pagination) | `GET /api/appointments?status=&service_id=&from=&to=` |
| Book | `POST /api/appointments` |
| View one | `GET /api/appointments/{id}` |
| Reschedule | `PATCH /api/appointments/{id}` |
| Cancel | `POST /api/appointments/{id}/cancel` |
| Admin statistics | `GET /api/admin/stats` |
| Reminder emails | `php artisan appointments:send-reminders` (scheduled hourly) |
| Mark past appointments completed | `php artisan appointments:complete-past` (scheduled hourly) |

### Booking rules

All of these live in `config/booking.php`:

- Open Monday to Friday, 09:00 to 17:00 (application timezone).
- A day is cut into back-to-back slots, each as long as the service's `duration_minutes`. A 45-minute service gives 09:00, 09:45, 10:30, ...
- You can only book a time that is exactly one of those slots, is in the future, and does not overlap another non-cancelled appointment **for the same service**.
- Overlap is checked per service, not globally. Two different services can be booked at the same time.

### Notifications

Booking, rescheduling, cancelling and the 24-hour reminder each send an email. They are queued (`ShouldQueue`) and only sent after the database transaction commits (`ShouldQueueAfterCommit`), so a rolled-back booking never sends an email. By default `MAIL_MAILER=log`, so emails go to `storage/logs/laravel.log`.

## Request flow

General path for every API call:

```
HTTP request
  -> routes/api.php                  picks the controller method
  -> throttle:api middleware          60 requests/minute per user or IP (login has its own 5/minute limit)
  -> auth:sanctum middleware          reads "Authorization: Bearer <token>", loads the User
  -> route model binding              turns {appointment} into an Appointment model (404 if missing)
  -> FormRequest                      authorize() calls the Policy (403), rules() validates input (422)
  -> Controller                       thin: passes clean input to an Action
  -> Action class                     business rules, DB transaction, notifications
  -> Eloquent model / scopes          builds SQL
  -> Database
  -> API Resource                     shapes the model into JSON
  -> JSON response
```

### Example: booking an appointment

`POST /api/appointments` with `{ "service_id": 1, "starts_at": "2026-11-10 09:45" }`

1. **Route** (`routes/api.php`) sends it to `AppointmentController@store`. It's inside the `auth:sanctum` group, so no token means 401.
2. **`StoreAppointmentRequest`** checks `AppointmentPolicy::create` (any logged-in user) and validates that `service_id` exists and `starts_at` is `Y-m-d H:i[:s]` with no timezone offset, so it is always read in the app timezone.
3. **`AppointmentController@store`** calls `CreateAppointment::handle(user, serviceId, startsAt)`.
4. **`CreateAppointment`**, inside a DB transaction:
   - Loads and locks the service row (`lockForUpdate`). This forces two simultaneous bookings for the same service to wait for each other, so both can't pass the overlap check at once.
   - Asks `ListAvailableSlots::isFree()` whether the time is a valid slot (open day, inside hours, on the grid, in the future) and, via the `Appointment::overlapping()` scope, whether it clashes with an existing booking for the **same service** (other services may book the same clock time — intentional product rule).
   - If not free it throws `AppointmentUnavailable`, which renders itself as a 422 JSON error on `starts_at`.
   - Otherwise it creates the appointment with status `confirmed` and queues the `AppointmentBooked` email.
5. **Controller** wraps the result in `AppointmentResource` (with `service` and `user` loaded) and returns **201**.

Reschedule follows the same pattern through `RescheduleAppointment` (it also locks the appointment row, ignores itself in the overlap check, and clears `reminded_at` so a new reminder can be sent). Cancel goes through `CancelAppointment` inside a transaction with `lockForUpdate` on the appointment row. Both refuse anything that is not `confirmed` by throwing `BookingConflict`, which renders as **409**.

### Who can see what

- `Appointment::visibleTo($user)` scope: admins see all appointments, customers only their own.
- `AppointmentPolicy`: view / cancel / reschedule allowed for the owner or an admin.
- `ServicePolicy`: anyone logged in can view; only admins can create / update / delete.
- `AdminStatsController` uses the `view-admin-stats` Gate defined in `AppServiceProvider` (admins only).
- `User::isAdmin()` is the single place that knows what "admin" means.

## Folder structure

Only the folders and files that matter. Everything else is the standard Laravel skeleton.

```
app/
  Actions/                  Business logic, one class per use case
    CreateAppointment.php       Book a slot (locks, validates slot, notifies)
    RescheduleAppointment.php   Move a confirmed appointment to a new slot
    CancelAppointment.php       Cancel a confirmed appointment
    ListAvailableSlots.php      Builds the day's slot grid and removes taken ones; isFree() validates one time
    BuildAdminStats.php         Aggregate queries for the admin dashboard
  Console/Commands/
    SendAppointmentReminders.php  Emails customers whose appointment starts within 24h, marks reminded_at
    CompletePastAppointments.php  Flips confirmed appointments to completed once ends_at has passed
  Enums/
    AppointmentStatus.php     confirmed | cancelled | completed
    Role.php                  admin | customer
  Exceptions/
    AppointmentUnavailable.php  "That time is no longer available." -> 422
  Http/
    Controllers/              Thin controllers, one per resource
      AuthController.php        register, login, logout, me
      ServiceController.php     CRUD for services (plain CRUD, no Action)
      AvailabilityController.php  single-action: free slots for a day
      AppointmentController.php   index, show, store, update (reschedule), cancel
      AdminStatsController.php    single-action: admin stats
    Requests/                 Validation + authorization per endpoint
    Resources/                JSON output shape (Appointment, Service, User)
  Models/
    User.php, Service.php, Appointment.php
  Notifications/              Booked, Rescheduled, Cancelled, Reminder emails (queued)
  Policies/                   AppointmentPolicy, ServicePolicy (auto-discovered by Laravel)
bootstrap/app.php           Registers routes; forces JSON error responses for api/* requests
config/booking.php          Business hours, open weekdays, page size, reminder window, stats settings
database/
  migrations/               Table definitions
  factories/                Fake data for tests (User has an admin() state)
  seeders/DatabaseSeeder.php  admin@example.com, customer@example.com, 3 services
routes/
  api.php                   All endpoints
  console.php               Schedules the reminder and complete-past commands hourly
tests/
  Feature/                  HTTP-level tests for each flow (auth, services, booking, availability, etc.)
  Unit/EnumTest.php
floorplan/                  A code-map visualisation tool, not part of the app
```

## Database tables

### Application tables

**users**

| Column | Notes |
|---|---|
| id | |
| name, email (unique), password (hashed) | |
| role | `admin` or `customer`, default `customer` |
| email_verified_at, remember_token, timestamps | Laravel defaults, not used by the API |

**services**

| Column | Notes |
|---|---|
| id | |
| name | Unique is enforced by validation, not by a DB index |
| duration_minutes | Also defines the slot length |
| price | decimal(10,2) |
| timestamps | |

**appointments**

| Column | Notes |
|---|---|
| id | |
| user_id | FK to users, restrict on delete |
| service_id | FK to services, restrict on delete |
| starts_at, ends_at | `ends_at = starts_at + duration_minutes` |
| status | `confirmed` / `cancelled` / `completed`, default `confirmed` |
| reminded_at | Set when the reminder email is queued, so it is only sent once |
| timestamps | |

Index: `(service_id, starts_at, ends_at)` to speed up the overlap check.

### Framework tables

- `personal_access_tokens`: Sanctum API tokens. Polymorphic link to `users` (`tokenable_type`, `tokenable_id`).
- `password_reset_tokens`, `sessions`: Laravel defaults, not used by this API.
- `cache`, `cache_locks`: database cache store.
- `jobs`, `job_batches`, `failed_jobs`: the database queue that holds the notification emails.

### Relationships

```
users 1 ──< appointments >── 1 services
users 1 ──< personal_access_tokens
```

- `User hasMany Appointment`
- `Service hasMany Appointment`
- `Appointment belongsTo User` and `belongsTo Service`

### Useful model scopes (`Appointment`)

- `overlapping(serviceId, start, end, exceptId)`: non-cancelled appointments of a service that clash with a time range.
- `visibleTo(user)`: all rows for admins, own rows for customers.
- `filtered(filters)`: applies the optional `status`, `service_id`, `from`, `to` filters from the index endpoint.
- `futureConfirmed()`: used to block deleting a service that still has upcoming bookings.

## Running it locally

```bash
composer install
cp .env.example .env
php artisan key:generate
# create the MySQL database named in DB_DATABASE first
php artisan migrate --seed
php artisan serve
php artisan queue:work      # needed for emails to actually be processed
```

Tests: `php artisan test` (91 tests, 1 skipped: the concurrency test only runs on MySQL with `--group=mysql`).
