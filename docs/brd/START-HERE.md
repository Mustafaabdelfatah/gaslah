# ابدأ من هنا — بناء منصة غسلة على هذا المشروع

> هذا الملف يوجّه أي جلسة (شات) جديدة لبناء البيزنس الكامل لمنصة **غسلة** (نظام مغاسل SaaS متعدد المنشآت) **داخل هذا المشروع**، بنفس بنيته وأسلوبه الحالي.

## القاعدة الأولى (الأهم): اتبع أسلوب هذا المشروع، لا أسلوب النظام القديم

مواصفة الأعمال (BRD) في `docs/brd/` مستخرجة من نظام قديم كان مبنياً بـ (توكن HMAC، قاعدة Prisma مشتركة، أسماء PascalCase). **هذه تفاصيل تنفيذ قديمة — تجاهلها.** خذ منها **البيزنس فقط** (المنطق، القواعد، التدفقات، الحقول، حركة الفلوس)، ونفّذه بأدوات هذا المشروع وأنماطه.

**قبل أي كود، ادرس أسلوب هذا المشروع:**
1. اقرأ `documentation/guide/` بالكامل: `architecture.md`, `authentication.md`, `authorization.md`, `database-models.md`, `api-reference.md`, `configuration.md`.
2. ادرس الـ domains المرجعية الموجودة كنموذج تحتذيه: `User` و `Country`:
   - `app/Http/Controllers/API/User/*`, `app/Services/User/UserService.php`, `app/Http/Requests/...`, `app/Http/Resources/...`, `app/Filters/User/*`, `app/Enum/User/*`, `app/Policies/User/*`, `app/Scopes/User/*`, `app/Models/User.php`.
   - الأساسيات: `app/Http/Controllers/API/BaseController.php`, `app/Models/BaseModel.php`, `app/Trait/Global/*` (AdvancedFilter, HasDeleteMethods, HasToggleActiveMethods, HasOrder, CreatedByObserver…), `app/Services/Global/*` (QueryHelper, SettingService, NotificationService, EncryptionService).
3. أي كيان/موديول جديد تبنيه، **قلّد نفس البنية بالحرف**: Controller رفيع في `API/{Domain}/` + `{Domain}Service` + Request + Resource + Filter + Enum + Policy + Scope حسب الحاجة.

## خريطة تحويل: مفاهيم النظام القديم → أدوات هذا المشروع

| البيزنس (في الـ BRD) | نفّذه هنا بـ |
|---|---|
| توكن HMAC مخصّص | **Laravel Sanctum** (موجود: `PersonalAccessToken`, `LoginController`) |
| أنواع التوكنات (staff/customer/driver…) | حراس/أدوار Sanctum + أبيليتيز، أو guards حسب `app/Guards/` |
| الأدوار والصلاحيات (StaffPermissions) | **spatie/laravel-permission** (موجود: `RoleController`, `PermissionController`, `Role`, `Permission`) |
| OTP (PosOtpController) | نظام OTP الموجود: `OTPController`, `OtpTypeEnum`, `app/Enum/Global/OtpTypeEnum` |
| تخزين JSON في جدول Setting | جداول حقيقية (انظر `docs/brd/14` + `schema/`)؛ استخدم `Setting`/`SettingService` الموجود فقط للإعدادات الحرّة الحقيقية |
| الحقول ثنائية اللغة | **spatie/laravel-translatable** (مثبّت) |
| الملفات/الصور | حزمة `media-manager` المثبّتة |
| التصدير/التقارير | حِزم `export-builder` / `report-builder` المثبّتة (وأدوات `app/Tools/`) |
| سجل التدقيق (AuditLog) | **spatie/laravel-activitylog** (موجود: `LogsActivityOptions`, migration activity_log) |
| PascalCase / cuid | أسلوب هذا المشروع (snake_case، bigint) — كما في مخطط `docs/brd/schema/` |

## أين تجد البيزنس

- **ابدأ بـ [`docs/brd/00-overview-architecture.md`](00-overview-architecture.md)** — فيه الأنماط المشتركة المقدّسة (نزاهة الفلوس، الأمان، التزامن، idempotency). **هذه القواعد تُنفَّذ كما هي مهما اختلفت البنية.**
- ملفات الموديولات `01`–`13`: كل موديول بكياناته وتدفقاته وقواعده وحالاته وصلاحياته وفجواته.
- **المخطط:** [`docs/brd/14-database-schema.md`](14-database-schema.md) + مجلد [`schema/`](schema/) — ~110 جدول. استخدمه كأساس للـ migrations، لكن **طابقه مع اصطلاحات وقاعدة هذا المشروع** (عدّل ما يلزم ليتّسق مع الجداول الموجودة مثل users/roles/permissions/settings).

## القواعد الذهبية (غير قابلة للتفاوض — من ملف 00)

1. **نزاهة الفلوس:** كل حركة محفظة عبر خدمة واحدة بـ `lockForUpdate`؛ الدفع بحضور العميل يتطلب OTP + حرق التوكن ذرّياً قبل تحرّك المال؛ كل ترحيل محاسبي idempotent على `(organization_id, source, ref_type, ref_id)`؛ أعد حساب الأسعار خادمياً دائماً.
2. **الأمان:** تحقّق حيّ كل طلب؛ عزل تام بين المنشآت (`organization_id` scope)؛ منع تصعيد الصلاحيات؛ fail-closed على OTP/الويبهوك؛ لا تسريب أسرار.
3. **التزامن:** أقفال صفوف على الأرصدة؛ فهارس فريدة/جزئية للثوابت (وردية/تسوية واحدة مفتوحة…)؛ idempotency keys.
4. **القيد المزدوج** في المحاسبة كما هو موصوف في [`08`](08-accounting-assets-payables.md) بالحرف.

## ترتيب البناء المقترح (موديول موديول)

| مرحلة | تبني | الملفات |
|---|---|---|
| 0 | دراسة أسلوب المشروع + تصميم الـ schema/migrations متوافقة معه | guide/ + `14` + `schema/` |
| 1 | المنشآت والفروع + المصادقة والأدوار (Sanctum + spatie) + tenancy scope | `01` + `12` |
| 2 | قلب الفلوس: المحاسبة + المحفظة + أساسيات المدفوعات | `08` + `05` |
| 3 | الكتالوج والعملاء | `02` |
| 4 | الطلبات و POS + OTP | `03` |
| 5 | الاشتراكات/الولاء + التوصيل + بوابة العميل | `06` + `04` + `07` |
| 6 | بوابات الدفع + التسويات + ZATCA | `05` + `11` |
| 7 | التقارير/التحليلات + المخزون/الورديات/البنوك | `09` |
| 8 | المراسلة/الدعم/المحتوى/الأفيليت | `10` |
| 9 | إدارة المنصة + الفوترة + dunning + الشركاء | `12` |
| 10 | السوق B2B + الإعدادات + التدقيق | `13` |

## مصدر ترحيل البيانات (مهم)

بيانات النظام القديم كاملة متاحة في قاعدة MySQL اسمها **`laundry_legacy`** (نفس سيرفر MySQL المحلي، root بلا باسورد). دي **نسخة مطابقة للنظام القديم** (أسماء PascalCase: `Account`, `Order`, `Payment`, `JournalLine`, `Customer`, `Organization`... ٨٠ جدول، ~١٥٤٥ صف حقيقي).

استخدمها كـ**مصدر ترحيل**:
- في مرحلة نقل البيانات، اقرأ منها وحوّل الصفوف لجداولك الجديدة (snake_case) عبر ربط `legacy_cuid` (المعرّف القديم cuid النصي) بالصف الجديد — كما هو موضّح في `docs/brd/14-database-schema.md` §9.
- الأرصدة (المحفظة، الحسابات) **أعد احتسابها** من الحركات (`WalletTransaction`, `JournalLine`) بدل نسخ الرصيد المخزّن، وتحقّق من التطابق.
- ملاحظات: المفاتيح الأجنبية شِيلت من النسخة المستوردة (الداتا متسقة أصلاً)؛ أسماء الجداول تُخزَّن lowercase على ويندوز فاستعلم `` `order` `` عادي.
- **لا تبني على هيكل `laundry_legacy`** — هو مرجع/مصدر فقط؛ الهيكل المعتمد هو مخططك الجديد.

## أسلوب العمل

- **قبل أي كود:** ارجع للمستخدم بخطة: فهمك للنظام، كيف ستطبّق البيزنس على بنية هذا المشروع، تصميم الجداول متوافقاً مع الموجود، وخطة المراحل.
- **امشِ موديول موديول.** لا تبدأ التالي قبل أن يكتمل الحالي ويُختبر.
- **اكتب tests** لكل flow مالي/أمني.
- **الفجوات** (في آخر كل ملف BRD) = نواقص النظام القديم — نفّذها صح، لكن نبّه المستخدم قبل كل واحدة.
- **لا تخترع بيزنس.** أي غموض أو تعارض، اسأل المستخدم.
- التواصل بالعربي المصري.
