<div dir="rtl">

## چه کاری انجام شد

دستور `appointments:send-reminders` برای نوبت‌های تأییدشده در ۲۴ ساعت آینده یک ایمیل یادآوری در صف می‌گذارد.
ستون `reminded_at` نمی‌گذارد همان نوبت دوباره یادآوری شود.
این دستور در زمان‌بند هر ساعت ثبت شده است.

## فایل‌های تغییر کرده

- `database/migrations/2026_10_30_120000_add_reminded_at_to_appointments_table.php`: ستون خالی‌پذیر `reminded_at`
- `app/Models/Appointment.php`: این ستون به فیلدهای قابل ذخیره و تبدیل تاریخ اضافه شد
- `config/booking.php`: طول پنجره در `reminder_hours`
- `app/Notifications/AppointmentReminder.php`: متن ایمیل یادآوری
- `app/Console/Commands/SendAppointmentReminders.php`: خود دستور
- `routes/console.php`: اجرای ساعتی
- `tests/Feature/SendAppointmentRemindersTest.php`: یک‌بار ارسال، لغوشده، و بیرون از پنجره

## چطور کار می‌کند

دستور نوبت‌هایی را انتخاب می‌کند که وضعیتشان تأییدشده است، `reminded_at` خالی است، و شروعشان بین الان و ۲۴ ساعت بعد است.
عدد ۲۴ در `reminder_hours` داخل `config/booking.php` است، نه وسط دستور.
برای هر نوبت، داخل یک `Transaction` زمان یادآوری ذخیره می‌شود و `AppointmentReminder` برای همان کاربر در صف قرار می‌گیرد.
این اعلان `ShouldQueue` و `ShouldQueueAfterCommit` است، پس اگر تراکنش برگردد نامه هم صف نمی‌شود.
نوبت لغوشده، یا نوبتی که دیرتر از این پنجره شروع می‌شود، انتخاب نمی‌شود.
اجرای دوباره دستور همان نوبت را دوباره نمی‌فرستد.

## یک تصمیم طراحی مهم

`reminded_at` را همان لحظه گذاشتن در صف می‌نویسم، نه بعد از اینکه کارگر صف ایمیل را واقعاً فرستاد.
اگر علامت را دیرتر بگذاریم، دو اجرای نزدیک دستور هر دو می‌توانند همان نامه را در صف بگذارند.
`ShouldQueueAfterCommit` نامه را به همان تراکنش وصل می‌کند، پس به‌روزرسانی ناتمام نامه نمی‌سازد.

## سؤال احتمالی مصاحبه

چرا با وجود صف، هنوز ستون `reminded_at` لازم است؟
صف فقط ارسال را عقب می‌اندازد. بدون این ستون، اجرای بعدی دستور همان نوبت را دوباره انتخاب می‌کند.

## چطور تست کنم

```bash
php artisan test
./vendor/bin/pint
```

هر دو پاس شدند. ۷۳ تست.

## پیام کامیت پیشنهادی

```
feat: queue reminder mail for upcoming appointments
```

</div>
