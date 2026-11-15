<div dir="rtl">

# بک‌لاگ جلوگیری از double-booking

نسخهٔ اصلاح‌شده نسبت به متن اولیهٔ مدیر محصول. فرض‌ها با کد فعلی پروژه هم‌تراز شده‌اند.

منبع حقیقت اسکیما: `database/migrations/2026_10_26_201539_create_appointments_table.php`  
منطق رزرو: `app/Actions/CreateAppointment.php`، `app/Actions/RescheduleAppointment.php`، `app/Models/Appointment.php`

---

## فرض پایه (قفل‌شده)

| مورد | واقعیت پروژه |
|---|---|
| فیلدهای نوبت | `user_id`, `service_id`, `starts_at`, `ends_at`, `status`, `reminded_at` |
| آنچه وجود ندارد | `provider_id`, `start_at`, `end_at`، soft delete، منبع staff/chair/provider |
| منبع کمیاب | **service** — تداخل فقط روی یک `service_id` |
| سرویس‌های مختلف | هم‌زمان رزرو شدن مجاز است |
| ایندکس فعلی | `(service_id, starts_at, ends_at)` — **یکتا نیست** |
| لغو | `status = cancelled`؛ از overlap کنار گذاشته می‌شود و بازه آزاد می‌شود |
| الگوی کد | Action (`CreateAppointment`) — نه `BookingService` |
| تداخل اسلات | HTTP **422** (`AppointmentUnavailable`) |
| تعارض وضعیت | HTTP **409** (`BookingConflict`) |
| Frontend | در این ریپو نیست (API-only) |

اسلات‌ها روی گرید `duration_minutes` همان سرویس‌اند؛ برای یک سرویس، دفاع آخر با unique روی `(service_id, starts_at)` برای نوبت‌های **فعال** منطقی است — به شرط استثنای `cancelled`.

---

## اختلاف با متن اولیهٔ PM

| ادعای PM | واقعیت / اصلاح |
|---|---|
| `provider_id`, `start_at` | `service_id`, `starts_at` |
| `BookingService` جدید | Actionهای موجود را نگه دار |
| تداخل → 409 + UI | تداخل → 422؛ UI خارج از ریپو |
| unique ساده + soft delete | unique شرطی برای غیرلغو؛ soft delete لازم نیست |
| تست ۱۰–۵۰ از صفر | تست ۲ پروسسی هست ولی ضعیف؛ باید تقویت قطعی شود |
| همه تسک‌ها از صفر | ۱ و ۳ عمدتاً Done؛ ارزش اصلی در ۲، ۵ و ۷ است |

---

## تسک‌ها

### تسک ۱: قانون کسب‌وکار

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | با تسک ۷ |

**هدف:** هر `service` در هر بازهٔ زمانی فقط یک نوبت غیالغوشده (`confirmed` یا `completed`) دارد؛ لغو بازه را آزاد می‌کند.

**معیار پذیرش:**
- در README بخش قوانین رزرو: تداخل per-`service_id`، نادیده گرفتن `cancelled`، و اینکه سرویس‌های مختلف هم‌زمان مجازند.
- رفتار `Appointment::scopeOverlapping` با این متن یکی باشد.

**یادداشت:** منطق در کد هست؛ README قفل را می‌گوید ولی قانون لغو/آزاد شدن را کامل نمی‌گوید.

---

### تسک ۲: constraint یکتا در دیتابیس

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | ۱ |

**هدف:** خط دفاع آخر وقتی قفل اپلیکیشن جا بماند یا کسی مستقیم در DB بنویسد.

**کار:** ایندکس یکتا فقط برای نوبت‌های فعال. پیشنهاد هم‌خوان با MySQL: ستون generated مثل  
`active_starts_at = IF(status = 'cancelled', NULL, starts_at)`  
+ `UNIQUE(service_id, active_starts_at)`  
(چند NULL در MySQL مجاز است → بعد از لغو همان `starts_at` دوباره قابل رزرو است.)  
Soft delete اضافه نکن مگر تصمیم محصول جداگانه.

**معیار پذیرش:**
- دو INSERT مستقیم با همان `service_id` + `starts_at` و status غیرلغو → خطای unique.
- بعد از cancel همان زمان دوباره قابل رزرو است.
- Reschedule با constraint نمی‌شکند.

**هشدار برای PM:** unique روی `(provider_id, start_at)` غلط است؛ unique ساده روی `(service_id, starts_at)` بدون استثنای cancelled، لغو را می‌شکند.

---

### تسک ۳: منطق رزرو داخل تراکنش

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | ۲ |

**هدف:** بررسی تداخل + ساخت نوبت در یک تراکنش با قفل.

**کار:** همان `CreateAppointment` / `RescheduleAppointment` را نگه دار. **`BookingService` نساز.**

**معیار پذیرش:**
- `DB::transaction` + `Service::lockForUpdate()` + `isFree(...)->lockForUpdate()` روی ردیف‌های تداخلی باقی بماند.
- بعد از افزودن unique: گرفتن `UniqueConstraintViolationException` و تبدیل به همان پاسخ تداخل اسلات (422).

---

### تسک ۴: مدیریت خطای تداخل

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | ۵ |

**هدف API:** وقتی اسلات گرفته شده، پاسخ پایدار و قابل‌مصرف برای کلاینت.

**تصمیم:** تداخل اسلات = **422** با بدنهٔ موجود (`message` + `errors.starts_at`). به 409 تغییر نده مگر محصول صریحاً breaking change بخواهد. در این API، 409 یعنی «وضعیت فعلی اجازهٔ این عمل را نمی‌دهد»، نه «اسلات پر است».

**Frontend:** در اسکوپ این ریپو نیست. معیار کلاینت:
- روی 422 با `errors.starts_at` پیام واضح نشان دهد.
- لیست را از `GET /services/{id}/availability` دوباره بگیرد.

**معیار پذیرش:** تست فیچر و/یا OpenAPI+README وضعیت و شکل JSON را قفل کند؛ تسک UI جدا اگر فرانت بعداً آمد.

---

### تسک ۵: تست همزمانی

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | ۳ |

**هدف:** در MySQL واقعی فقط یک برنده؛ بازنده‌ها پاسخ تداخل تمیز (نه 500/deadlock).

**کار:**
- MySQL با `DB_DATABASE` ختم‌شده به `_test` (مثل `ConcurrentBookingTest` فعلی).
- حداقل یک تست **قطعی** دو‌اتصال (طبق `docs/REVIEW_2.md`: بدون قفل race/deadlock؛ با قفل دومی منتظر می‌ماند و 422 می‌گیرد).
- stress اختیاری با N پروسس (مثلاً ۸–۱۶) که دقیقاً یک ردیف confirmed بسازد.
- ادعای README را با تستی که با حذف قفل می‌شکند هم‌تراز کن.

**معیار پذیرش:** فقط MySQL؛ SQLite skip؛ یک برنده؛ بازنده‌ها `AppointmentUnavailable` / HTTP 422؛ بدون 500.

---

### تسک ۶ (اختیاری): idempotency

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | ۶ |

**وضعیت واقعی:** تکرار POST book برای همان اسلات ۱ ردیف می‌ماند (دومی 422). تکرار reschedule با همان `starts_at` → 200 بدون ایمیل دوم و بدون clear کردن `reminded_at`.

**کار پیشنهادی:**
- برای book: Idempotency-Key ارزشش کمتر از fix رزرو مجدد است.
- اولویت بالاتر: reschedule وقتی `starts_at` عوض نشده → 200 بدون ایمیل و بدون clear کردن `reminded_at`؛ assertion تست را اصلاح کن.

**معیار پذیرش:** دابل‌کلیک/retry همان نتیجه را بدون اثر جانبی اضافه (ردیف یا ایمیل اضافه) بدهد.

---

### تسک ۷: مستندسازی

| فیلد | مقدار |
|---|---|
| وضعیت | Done |
| اولویت اجرا | ۴ (با تسک ۱) |

**کار در README:**
1. مشکل: دو درخواست هم‌زمان می‌توانستند هر دو overlap check را رد کنند.
2. چرا **هر دو** قفل ردیف service و (در صورت پیاده شدن) unique فعال: قفل → پاسخ تمیز و جلوگیری از deadlock؛ unique → دفاع آخر.
3. قانون: per-`service_id`؛ cancelled آزاد می‌کند؛ سرویس‌های مختلف هم‌زمان OK.
4. نحوهٔ اجرای تست MySQL و نتیجهٔ مورد انتظار.
5. کد وضعیت: تداخل اسلات 422؛ تعارض وضعیت 409.

---

## ترتیب اجرا

1. **تسک ۲** — unique فعال (بزرگ‌ترین شکاف)
2. **تسک ۳** — wrap کردن violation → 422 (بدون BookingService)
3. **تسک ۵** — تست قطعی MySQL
4. **تسک ۱ + ۷** — README / قوانین
5. **تسک ۴** — قفل قرارداد 422 در تست/OpenAPI؛ بدون UI
6. **تسک ۶** — اختیاری؛ اولویت با idempotency رزرو مجدد reschedule

---

## ارجاع سریع فایل‌ها

| موضوع | مسیر |
|---|---|
| ساخت نوبت + قفل | `app/Actions/CreateAppointment.php` |
| overlap / لغو آزاد می‌کند | `app/Models/Appointment.php` → `scopeOverlapping` |
| پاسخ 422 تداخل | `app/Exceptions/AppointmentUnavailable.php` |
| پاسخ 409 وضعیت | `app/Exceptions/BookingConflict.php` |
| تست همزمانی فعلی | `tests/Feature/ConcurrentBookingTest.php` |
| نقد ضعف تست | `docs/REVIEW_2.md` (T2) |

</div>
