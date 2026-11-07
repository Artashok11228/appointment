# تحویل بررسی QA برای مدل بعدی

این فایل وضعیت بررسی و تست‌های انجام‌شده در تاریخ **۲۰۲۶-۱۱-۰۷** را ثبت می‌کند تا مدل بعدی بتواند اصلاحات را با شواهد و تست پیش ببرد. این فایل خودش گزارش انجام فیکس نیست. پیش از شروع، وضعیت فعلی کد و Git را دوباره بررسی کن؛ ممکن است بعد از این گزارش تغییر کرده باشند.

## ۱. محل پروژه و وضعیت تحویل

- ریشهٔ پروژه: `D:\Laravel-Projects\Resume\book appoinment\appointment`
- پوشهٔ اولیهٔ چت `C:\Users\n n\Documents\ChatGPT\appoinment` بود؛ کد Laravel در مسیر D قرار دارد.
- HEAD هنگام بررسی: `832ab6d` — `fix: harden booking safety and document product decisions`.
- محیط اجرای بررسی: PHP `8.4.27`، Laravel `13.38.0`، Sanctum `4.3.3`، PHPUnit `12.5.40`، Pint `1.32.1`.
- در مرحلهٔ QA فقط سه فایل تست زیر اضافه شد؛ هیچ فایل اجرایی برنامه، تنظیمات، migration، وابستگی یا تست قبلی اصلاح نشد.
- این سه فایل هنگام تحویل **untracked و بدون commit** هستند. آن‌ها را کار موجود کاربر در نظر بگیر و حفظ کن.
- این سند نیز به درخواست کاربر اضافه شده است. هیچ commit، push، merge یا deployment انجام نشده است.
- `AGENTS.md` ریشه و مهارت‌های محلی Laravel و Testing را رعایت کن. هنگام بررسی، `.codegraph/` و `.ai/rules/` وجود نداشتند؛ در صورت تغییر وضعیت، قواعد مربوط به آن‌ها اعمال می‌شود.

## ۲. نتیجهٔ واقعی اجراها

| اجرا | کل سناریو | پاس | شکست assertion | خطای اجرای تست | skip |
|---|---:|---:|---:|---:|---:|
| مجموعهٔ قبلی، پیش از افزودن تست‌ها | ۹۳ | ۹۲ | ۰ | ۰ | ۱ |
| مجموعهٔ نهایی با سه فایل جدید | ۱۱۶ | ۱۰۵ | ۱۰ | ۰ | ۱ |
| سهم تست‌های جدید در اجرای نهایی | ۲۳ | ۱۳ | ۱۰ | ۰ | ۰ |

- اجرای کامل نهایی با ترتیب تصادفی و seed ثابت `1107` انجام شد؛ **۴۱۴ assertion** ثبت شد.
- تمام ۱۰ شکست در دو فایل جدید `ReminderReliabilityTest` و `AppointmentTemporalIntegrityTest` هستند؛ تست‌های قبلی همچنان پاس شدند.
- اجرای جداگانهٔ اولیهٔ گروه جدید، پیش از افزودن آخرین سناریوی جابه‌جایی داخل بازه، ۲۲ سناریو داشت: ۱۳ پاس و ۹ شکست. این نتیجهٔ قدیمی را با نتیجهٔ نهایی ۲۳ سناریویی اشتباه نگیر.
- Pint برای فایل‌های جدید پاس شد؛ `--dirty --format agent` و اجرای صریح روی سه فایل انجام شد.
- تک skip متعلق به `ConcurrentBookingTest` قبلی است که به MySQL واقعی نیاز دارد. نتیجهٔ قفل‌گذاری هم‌زمان MySQL در این بررسی تأیید اجرایی نشده است.

گزارش‌های محلی تولیدشده:

- `storage/framework/testing/adversarial-full-junit.xml`: گزارش نهایی معتبر، ۱۱۶ تست، ۱۰ failure، صفر error، یک skip.
- `storage/framework/testing/adversarial-junit.xml`: گزارش اجرای اولیهٔ ۲۲ سناریویی؛ نسخهٔ نهایی نیست.

این XMLها فایل‌های موقت داخل storage هستند و ممکن است در clone یا محیط بعدی موجود نباشند. برای نتیجهٔ جدید، تست‌ها را دوباره اجرا کن.

## ۳. فایل‌ها و پوشش اضافه‌شده

هر سه کلاس عضو `#[Group('adversarial')]` هستند. کلاس زمانی علاوه بر آن عضو `#[Group('product-contract')]` است.

### `tests/Feature/AdversarialBookingTest.php`

**۱۱ سناریو؛ همه پاس شدند:**

| متد | تعداد case | ضمانت مورد بررسی |
|---|---:|---|
| `test_returns_422_for_every_shape_of_overlap_without_writing_or_notifying` | ۴ | هم‌پوشانی ابتدا/انتها، دربرگرفتن بازهٔ جدید توسط قبلی و برعکس؛ پاسخ ۴۲۲، بدون نوشتن یا اعلان |
| `test_touching_intervals_return_201_and_do_not_count_as_overlaps` | ۲ | پایان قبلی برابر شروع جدید، یا شروع قبلی برابر پایان جدید، تداخل محسوب نمی‌شود |
| `test_booking_ignores_forged_ownership_status_end_time_and_reminder_fields` | ۱ | جعل `user_id`، `status`، `ends_at` و `reminded_at` در ساخت رزرو نادیده گرفته می‌شود |
| `test_rescheduling_ignores_attempts_to_transfer_ownership_or_switch_services` | ۱ | payload جابه‌جایی نمی‌تواند مالک، سرویس، وضعیت یا مدت واقعی نوبت را عوض کند |
| `test_a_service_that_cannot_finish_before_closing_returns_422` | ۳ | زمان روی grid اما پایان بعد از تعطیلی؛ مدت بیش از کل روز کاری؛ شروع دقیقاً هنگام تعطیلی |

این موارد برای جلوگیری از regression حفظ شوند. عبور از بررسی مجوز یا پذیرش فیلدهای اضافه راه‌حل شکست‌های دیگر نیست.

### `tests/Feature/ReminderReliabilityTest.php`

**۸ تست؛ ۲ پاس و ۶ شکست.** جزئیات شش شکست در بخش بعد است.

دو تست پاس‌شدهٔ مهم:

- `test_a_successful_reminder_reaches_the_customer_through_the_real_database_queue`: job واقعاً در صف database قرار می‌گیرد، اجرا می‌شود و ایمیل با گیرنده، موضوع و زمان درست در ArrayTransport ثبت می‌شود.
- `test_an_outer_transaction_rollback_discards_both_the_booking_and_its_queued_mail`: rollback تراکنش بیرونی، هم رزرو و هم اعلان آن را حذف می‌کند؛ هیچ job یا ایمیلی باقی نمی‌ماند.

### `tests/Feature/AppointmentTemporalIntegrityTest.php`

**۲ متد × ۲ دادهٔ مرزی = ۴ سناریو؛ هر چهار شکست خوردند.** این فایل قرارداد محصول پیشنهادی را بررسی می‌کند، نه یک الزام قبلاً تأییدشده توسط مالک محصول.

## ۴. شکست‌های یادآوری و راهنمای اصلاح

### R1 — پس از شکست enqueue، یادآوری دیگر قابل retry نیست

تست: `test_a_failed_enqueue_leaves_the_reminder_retryable`

مسیرهای اصلی:

- `app/Console/Commands/SendAppointmentReminders.php`، متدهای `due()` و `remind()`.
- `app/Notifications/AppointmentNotification.php`، پیاده‌سازی `ShouldQueueAfterCommit`.

سناریو:

1. زمان روی `2026-11-02 08:00:00` ثابت می‌شود؛ نوبت تأییدشده ساعت ۱۲ شروع می‌شود.
2. روی رویداد `JobQueueing` فقط برای `AppointmentReminder` خطای `RuntimeException('Injected queue outage.')` تزریق می‌شود.
3. فرمان یادآوری اجرا می‌شود؛ enqueue شکست می‌خورد و جدول jobs خالی است.
4. انتظار: `reminded_at` خالی بماند تا با رفع خرابی، اجرای بعدی دقیقاً یک job بسازد.
5. واقعیت: `reminded_at` مقدار گرفته است؛ assertion مربوط به null شکست می‌خورد. بخش retry پس از آن در اجرای قرمز فعلی اجرا نمی‌شود.

علت: marker داخل تراکنش ذخیره می‌شود، ولی `ShouldQueueAfterCommit` ثبت job را به پس از commit منتقل می‌کند. شکست در آن مرحله، commit قبلی را برنمی‌گرداند. کامنت فعلی متد `remind()` دربارهٔ rollback در شکست queue گمراه‌کننده است.

این رفتار علاوه بر تست با یک probe جداگانه روی SQLite حافظه‌ای و شکست واقعی ثبت job نیز دیده شد: پس از شکست، اجرای بعدی `Queued 0 reminder(s).` گزارش کرد. آن probe فایل دائمی ایجاد نکرد؛ تست regression موجود، رویداد queue را برای تزریق خرابی به کار می‌گیرد.

جهت اصلاح: ثبت قصد ارسال و قابل retry بودن آن باید با marker سازگار و پایدار باشد. برای driver فعلی database، امکان ثبت اتمیک job و marker روی همان connection را بررسی کن؛ برای صف مستقل، الگوی durable intent/outbox یکی از گزینه‌هاست. انتخاب را متناسب با اندازهٔ پروژه نگه دار. پاک‌کردن کورکورانهٔ marker در catch ممکن است با جابه‌جایی یا اجرای دیگر رقابت کند.

**برای سبزکردن این تست، `ShouldQueueAfterCommit` را از تمام اعلان‌ها حذف نکن.** تست سبز rollback بیرونی باید همچنان معتبر بماند.

### R2 — یادآوری صف‌شده بعد از لغو ارسال می‌شود

تست: `test_a_queued_reminder_is_not_delivered_after_the_customer_cancels`

1. یادآوری نوبت ساعت ۱۲ وارد صف می‌شود.
2. مشتری از endpoint لغو استفاده می‌کند؛ وضعیت واقعاً `cancelled` می‌شود.
3. اولین job صف، یعنی یادآوری قبلی، اجرا می‌شود؛ job ایمیل لغو هنوز در صف می‌ماند.
4. انتظار: هیچ ایمیلی از job یادآوری قبلی ارسال نشود.
5. واقعیت: موضوع `Appointment reminder` در ArrayTransport ثبت می‌شود.

جهت اصلاح: اعتبار یادآوری هنگام پردازش job دوباره بررسی شود. اعتبار هنگام انتخاب نوبت در scheduler برای تأخیر صف کافی نیست. منطق محدودسازی یادآوری را بی‌دلیل به ایمیل لغو/تأیید/جابه‌جایی تعمیم نده.

### R3 — یادآوری قبلی پس از انتقال به خارج از بازهٔ یادآوری باقی می‌ماند

تست: `test_a_queued_reminder_for_the_old_time_is_suppressed_after_rescheduling_outside_the_window`

1. در ساعت ۸ روز ۲ نوامبر، یادآوری نوبت ساعت ۱۲ همان روز صف می‌شود.
2. نوبت با API به `2026-11-04 12:00:00` منتقل می‌شود؛ `reminded_at` نیز خالی می‌شود.
3. job یادآوری قبلی اجرا می‌شود.
4. انتظار: job مربوط به occurrence قبلی بی‌اثر باشد.
5. واقعیت: ایمیل یادآوری ارسال می‌شود، با اینکه نوبت جدید خارج از بازهٔ ۲۴ساعته است.

نکته: اعلان مدل Appointment را نگه می‌دارد و serialization استاندارد Laravel مدل را هنگام پردازش دوباره بازیابی می‌کند؛ داشتن model در notification به معنای نگه‌داشتن snapshot زمان اولیه نیست.

### R4 — پس از قطعی worker، یادآوری نوبت تمام‌شده ارسال می‌شود

تست: `test_a_delayed_worker_does_not_deliver_a_reminder_after_the_appointment_has_ended`

1. یادآوری نوبت ۱۲ تا ۱۳ در ساعت ۸ صف می‌شود.
2. زمان به ساعت ۱۴ منتقل و فرمان `appointments:complete-past` اجرا می‌شود.
3. وضعیت نوبت `completed` است؛ job قدیمی اکنون اجرا می‌شود.
4. انتظار: ایمیل reminder منقضی‌شده ارسال نشود.
5. واقعیت: `Appointment reminder` ارسال می‌شود.

جهت اصلاح: علاوه بر وضعیت، زمان معتبر ارسال و ارتباط job با نوبت فعلی بررسی شود. صرفاً سریع‌ترکردن scheduler مشکل job عقب‌افتاده را حل نمی‌کند.

### R5 — انتقال داخل بازهٔ ۲۴ساعته، دو یادآوری ایجاد می‌کند

تست: `test_rescheduling_inside_the_window_invalidates_the_old_reminder_without_suppressing_the_new_one`

1. در ساعت ۸، reminder نوبت ساعت ۱۲ صف می‌شود.
2. نوبت با API به ساعت ۱۵ همان روز جابه‌جا می‌شود؛ ایمیل reschedule نیز صف می‌شود.
3. فرمان reminder دوباره اجرا می‌شود؛ reminder زمان جدید هم صف می‌شود.
4. سه job پردازش می‌شوند.
5. انتظار موضوع ایمیل‌های واقعاً ارسال‌شده:

```text
Appointment rescheduled
Appointment reminder
```

واقعیت:

```text
Appointment reminder
Appointment rescheduled
Appointment reminder
```

**فقط چک‌کردن `confirmed`، آینده‌بودن یا داخل بازهٔ ۲۴ساعته بودن کافی نیست.** reminder قدیمی باید به occurrence/نسخهٔ زمان‌بندی اولیه وابسته باشد تا پس از جابه‌جایی نامعتبر شود. reminder معتبر زمان جدید نیز باید ارسال شود. snapshot تغییرناپذیر زمان یا شناسه/نسخهٔ برنامه‌ریزی را متناسب با طراحی بررسی کن.

### R6 — اجرای متداخل فرمان‌ها دو job برای یک یادآوری می‌سازد

تست: `test_two_interleaved_reminder_runs_enqueue_only_one_mail_for_the_same_appointment`

تست در رویداد `Appointment::retrieved` یک نقطهٔ yield ایجاد می‌کند: اجرای اول نوبت را خوانده است؛ اجرای دوم کامل می‌شود؛ سپس اجرای اول با مدل قدیمی خود ادامه می‌دهد. این شبیه‌سازی قطعی interleaving است، **تست دو پردازش واقعی یا اثبات رفتار lock در MySQL نیست**.

- انتظار: یک ردیف در jobs.
- واقعیت: دو ردیف در jobs.
- علت: `due()->get()` بیرون تراکنش انجام می‌شود و `remind()` با مدل انتخاب‌شده، بدون claim اتمیک و بازبینی شرط‌ها، marker را می‌نویسد و اعلان می‌فرستد.

جهت اصلاح: claim اتمیک یا قفل و بازخوانی شروط در تراکنش را همراه با راه‌حل R1 طراحی کن. در تغییر هم‌زمان نوبت، وضعیت، زمان، marker و نسخهٔ occurrence باید معتبر باشند. `withoutOverlapping()` و در صورت نیاز `onOneServer()` لایهٔ محافظ scheduler هستند؛ به‌تنهایی اجرای دستی یا ورودی‌های دیگر به فرمان را ایمن نمی‌کنند.

تست فعلی تعداد enqueue را بررسی می‌کند؛ از آن ادعای تضمین exactly-once تحویل ایمیل در خرابی‌های شبکه/worker استخراج نکن.

## ۵. چهار شکست وابسته به تصمیم محصول

فایل: `tests/Feature/AppointmentTemporalIntegrityTest.php`، گروه `product-contract`.

متدها:

- `test_returns_409_for_cancelling_an_ended_appointment_before_the_completion_command_runs`
- `test_returns_409_for_moving_an_ended_appointment_into_the_future`

هر دو متد دو case دارند:

- زمان دقیق پایان: `2026-11-02 10:00:00`.
- پس از پایان و قبل از اجرای ساعتی بعدی: `2026-11-02 10:15:00`.

نوبت اولیه از ۹ تا ۱۰ است و عمداً هنوز `confirmed` باقی مانده است. در درخواست reschedule، مقصد ساعت ۱۲ و یک slot آزاد و معتبر است.

- انتظار پیشنهادی: پاسخ **۴۰۹**، بدون تغییر نوبت و بدون notification.
- واقعیت در هر چهار case: پاسخ **۲۰۰**؛ لغو یا انتقال انجام می‌شود.
- علت: `CancelAppointment` و `RescheduleAppointment` فقط status را کنترل می‌کنند؛ پایان واقعی نوبت را بررسی نمی‌کنند. فرمان `CompletePastAppointments` ساعتی اجرا می‌شود و فاصلهٔ زمانی باقی می‌ماند.

این قرارداد در مرحلهٔ QA پیشنهاد شد؛ کاربر هنوز صریحاً ممنوعیت تغییر نوبت پایان‌یافته را تأیید نکرده است. آن را به‌عنوان باگ امنیتی قطعی یا تصمیم نهایی مالک محصول معرفی نکن. پیش از تغییر این سیاست، تصمیم محصول را روشن کن؛ چهار تست را برای پنهان‌کردن اختلاف حذف یا بی‌صدا تغییر نده.

اگر قرارداد پذیرفته شد، بررسی پایان باید روی رکورد قفل‌شده در Action انجام شود تا به scheduler و مدل قدیمی route binding متکی نباشد. دربارهٔ نوبت در حال اجرا، محدودیت پیش از شروع و استثنای admin تصمیم تازه‌ای در این گزارش گرفته نشده است.

## ۶. ویژگی‌های مهم طراحی تست که باید حفظ شوند

- `ReminderReliabilityTest` از **DatabaseMigrations** استفاده می‌کند تا commit واقعی رخ دهد. جایگزینی آن با `RefreshDatabase` می‌تواند مرز شکست after-commit را تغییر دهد و نتیجه‌ای متفاوت از اجرا در برنامه بسازد.
- این کلاس پیش از migration بررسی می‌کند DB حتماً SQLite با نام `:memory:` باشد؛ در محیط دیگر skip می‌شود. این محافظ را برای راحت‌ترشدن اجرا حذف نکن.
- در کلاس یادآوری، `queue.default=database` و `mail.default=array` است. تست‌ها job واقعی را با `Queue::pop()` و `fire()` اجرا می‌کنند؛ `Notification::fake()` یا `Queue::fake()` جایگزین این مسیر نشده است.
- ایمیل فقط در ArrayTransport حافظه‌ای ثبت می‌شود؛ هیچ SMTP واقعی یا ایمیل خارجی ارسال نشده است.
- زمان تست‌ها ثابت است؛ ساعت واقعی ماشین در سناریوها دخالت ندارد.
- تست مثبت تحویل reminder و تست rollback بیرونی عمداً در کنار تست‌های قرمز قرار دارند؛ حذف کل اعلان‌ها یا خاموش‌کردن صف نباید راهی برای سبزکردن مجموعه باشد.
- برخی assertionها به طراحی فعلی صف وابسته‌اند: مثلاً وجود سه job در سناریوی R5 و انتشار exception در R1. اگر راه‌حل صحیح، job قدیمی را زودتر حذف می‌کند یا خطا را به شکل دیگری گزارش می‌دهد، تست را با توضیح فنی تطبیق بده؛ ضمانت‌های قابل مشاهدهٔ عدم گم‌شدن، عدم تکرار و عدم ارسال یادآوری منقضی باید حفظ شوند.

## ۷. دستور بازتولید روی PowerShell

این متغیرها فقط برای session ترمینال تست تنظیم شوند؛ `.env` واقعی را برای اجرای این بررسی تغییر نده.

```powershell
Set-Location -LiteralPath 'D:\Laravel-Projects\Resume\book appoinment\appointment'

$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
$env:MAIL_MAILER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'

# تمام ۲۳ سناریوی جدید
php vendor/phpunit/phpunit/phpunit --group=adversarial --do-not-cache-result

# بررسی فنی جدید، بدون چهار سناریوی قرارداد پیشنهادی محصول
php vendor/phpunit/phpunit/phpunit --group=adversarial --exclude-group=product-contract --do-not-cache-result

# بازتولید اجرای کامل نهایی
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --order-by=random --random-order-seed=1107 --log-junit storage/framework/testing/adversarial-full-junit.xml
```

کلاس یادآوری داخل تست، queue را از sync به database تغییر می‌دهد؛ مقدار محیطی بالا مانع اجرای مسیر واقعی آن نیست. اگر پوشهٔ گزارش در محیط جدید موجود نبود، آن را فقط در `storage/framework/testing` ایجاد کن یا گزینهٔ `--log-junit` را موقتاً حذف کن.

## ۸. محدودیت‌ها و نکات محیطی مشاهده‌شده

- در sandbox ویندوز این جلسه، `is_readable('vendor/autoload.php')` مقدار false می‌داد، درحالی‌که فایل وجود داشت و `require` موفق بود. PHPUnit به همین دلیل پیام `Cannot open bootstrap script` می‌داد.
- اجرای همان تست‌ها خارج از sandbox با تنظیمات SQLite حافظه‌ای، مشکل را رفع کرد. این خطا باگ application یا دلیل تغییر vendor/autoload، ACLها یا PHPUnit نیست. اگر تکرار شد، محدودیت محیط اجرا را بررسی و از مسیر مجاز ابزار برای اجرا استفاده کن.
- `composer audit --locked --no-dev --format=json` به خطای DNS برای مخزن Composer تنظیم‌شده، `registry.directam.org`، خورد. دربارهٔ نبود advisory امنیتی ادعایی نشده است؛ audit کامل نشده است.
- هنگام اجرای `--list-groups` روی مجموعهٔ قبلی، فقط گروه `default` دیده شد و `--group=mysql --list-tests` تستی انتخاب نکرد. تست قبلی `ConcurrentBookingTest` از docblock `@group mysql` استفاده می‌کند؛ سازگاری این metadata با PHPUnit 12 را بررسی کن. این اشکال در مرحلهٔ QA اصلاح نشد. گروه‌های تست جدید با attribute تعریف شده‌اند.
- تست MySQL قبلی `migrate:fresh` اجرا می‌کند و فقط نام دیتابیس با پسوند `_test` را می‌پذیرد. این بررسی روی دیتابیس MySQL اجرا نشده است؛ برای اجرا فقط دیتابیس اختصاصی و قابل حذف تست استفاده کن.
- نبود frontend، تست بار، تست SMTP خارجی یا اجرای production در این گزارش به معنای تأیید آن حوزه‌ها نیست؛ این کارها انجام نشده‌اند.

## ۹. مسیر پیشنهادی ادامه و معیار پذیرش

1. وضعیت Git، نسخه‌های نصب‌شده، قواعد پروژه و خود تست‌ها را بررسی کن. فایل‌های جدید این گزارش را حفظ کن.
2. ابتدا `ReminderReliabilityTest` را بازتولید کن و مرز commit، claim و enqueue را روشن کن. R1 و R6 باید با هم حل شوند؛ راه‌حل یکی نباید دیگری را خراب کند.
3. اعتبار reminder هنگام پردازش را بر اساس وضعیت، زمان و occurrence/نسخهٔ زمان‌بندی اعمال کن؛ هر دو سناریوی انتقال داخل و خارج پنجره را نگه دار.
4. دربارهٔ قرارداد نوبت پایان‌یافته تصمیم محصول را روشن کن؛ سپس Actionها و تست‌ها را مطابق تصمیم اصلاح کن.
5. ابتدا تست متاثر، سپس گروه adversarial و در پایان مجموعهٔ کامل با seed `1107` را اجرا کن. اگر کد PHP تغییر کرد، Pint لازم است.
6. نتیجه را با تعداد دقیق pass/fail/skip و موارد اجرا‌نشده گزارش کن. تعداد ۱۰ شکست، به معنی ۱۰ علت مستقل نیست.

معیار رفتاری اصلاح فنی: reminder در خرابی enqueue گم نشود؛ reminder مربوط به لغو، پایان یا occurrence قبلی ارسال نشود؛ تداخل انتخاب نوبت دو enqueue برای همان occurrence نسازد؛ یادآوری معتبر و ایمیل‌های واقعی دیگر همچنان کار کنند؛ rollback رزرو همچنان هیچ job یا ایمیلی باقی نگذارد.

مواردی مانند بازطراحی ظرفیت کارکنان، سقف نوبت فعال، سیاست no-show یا refactor گسترده در مرحلهٔ review مطرح شدند، ولی تست شکست‌خورده و تصمیم محصول تثبیت‌شده برای آن‌ها در این تحویل وجود ندارد. آن‌ها را بی‌دلیل وارد فیکس‌های فوق نکن.
