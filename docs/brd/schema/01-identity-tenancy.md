# مخطط الجداول — 01 الهوية والتعدّدية (Identity & Tenancy)

> مصدر التصميم: `docs/brd/01-auth-roles-tenancy.md` (كامل) + قسم كيانات المنشأة في `docs/brd/12-platform-tenants.md` (§2).
> **مجال هذا الملف:** المنشآت، الفروع، المستخدمون (موظف/أدمن منصّة)، عضويات الفروع، تجاوزات صلاحيات المستخدم الفردية، صلاحيات أدمن المنصّة، قائمة إبطال التوكنات، سجلّ الأمان (الدخول/القفل)، وكتالوج الخصائص (الاستحقاقات).
>
> **اصطلاحات عامة مطبَّقة في كل الجداول أدناه:** PK = `id` bigint unsigned auto-increment. الكيانات المستوردة من نظام Next.js/Prisma القديم تحمل `legacy_cuid` (نص، nullable، فريد) لربط الاستيراد. كل جدول مملوك لمنشأة يحمل `organization_id`؛ والمنطاق على الفرع يحمل `branch_id`. الأموال `decimal(14,2)`، النِسَب `decimal(5,2)`. الحالات/الأنواع أعمدة `string` مدعومة بـ PHP enum + قيد CHECK بكل القيم. الطوابع `created_at`/`updated_at` ما لم يُذكر خلاف ذلك.

---

## نظرة مجالية سريعة

| الجدول | كان (Prisma / Setting) | الغرض |
|---|---|---|
| `organizations` | `Organization` | منشأة المستأجر + أعمدة تحكّم المنصّة |
| `branches` | `Branch` | فرع تابع لمنشأة |
| `users` | `User` | موظف منشأة و/أو أدمن منصّة |
| `user_branches` | `UserBranch` | عضوية مستخدم في فرع + دوره فيه |
| `user_permission_overrides` (+ `_items`) | Setting `user.permissions:{userId}` | تجاوز صلاحيات موظف فردي |
| `user_platform_permissions` | `User.platformPermissions` (text[]) | صلاحيات أدمن المنصّة الدقيقة |
| `admin_role_presets` (+ `_permissions`) | PlatformConfig `platform.customRoles` | تشكيلات صلاحيات أدمن مُسمّاة |
| `token_denylist` | Cache `TokenDenylist` (ملفّي) | إبطال jti مبكّر + حرق pos-otp الذرّي |
| `security_logs` | AuditLog `LOGIN_OK/LOGIN_FAILED` | محاولات الدخول والقفل بالنطاق (بريد+IP) |
| `features` | `FeatureRegistry` (كود ثابت) | كتالوج الخصائص المتحكَّم بها (الاستحقاقات) |

---

### `organizations`  ← كان: `Organization`
> منشأة المستأجر (tenant): بيانات العمل الأساسية + أعمدة تحكّم المنصّة لكل منشأة.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | فريد — cuid المنشأة في Prisma القديم (للاستيراد) |
| `name` | string | لا | — | اسم المنشأة |
| `slug` | string | لا | — | فريد — آخر 8 أحرف من المعرّف القديم، lowercase (يُولَّد عند الإنشاء) |
| `custom_domain` | string | نعم | — | فريد (partial) — نطاق مخصّص |
| `default_currency` | string(3) | لا | `SAR` | عملة العرض الافتراضية |
| `tax_rate` | decimal(5,2) | لا | `15.00` | نسبة ضريبة القيمة المضافة لكل منطقة (السعودية 15، الإمارات 5…) |
| `phone` | string | نعم | — | |
| `email` | string | نعم | — | |
| `address` | string | نعم | — | |
| `cr_number` | string | نعم | — | رقم السجل التجاري |
| `vat_number` | string | نعم | — | الرقم الضريبي |
| `receipt_footer` | text | نعم | — | تذييل الفاتورة |
| `receipt_width` | smallint unsigned | لا | `80` | عرض الفاتورة (مم) |
| `brand_primary` | string | نعم | — | لون الهوية الأساسي |
| `brand_accent` | string | نعم | — | لون الهوية المكمّل |
| `logo_url` | string | نعم | — | شعار المنشأة |
| `settings` | json | نعم | — | إعدادات المنشأة العامة غير المنظّمة (يبقى JSON — semi-structured) |
| `is_suspended` | boolean | لا | `false` | تحكّم منصّة: إيقاف صارم مستقل عن الاشتراك (للقراءة فقط + خروج من Dunning) |
| `feature_overrides` | json | نعم | — | تحكّم منصّة: خريطة `{featureKey: bool}` تفرض on/off للمفاتيح gated فقط |
| `max_branches_override` | int unsigned | نعم | — | تحكّم منصّة: تجاوز حدّ الفروع من الخطة |
| `max_users_override` | int unsigned | نعم | — | تحكّم منصّة: تجاوز حدّ المستخدمين من الخطة |
| `admin_follow_up` | boolean | لا | `false` | تحكّم منصّة: علم متابعة CRM |
| `admin_tags` | json | نعم | — | تحكّم منصّة: مصفوفة وسوم إدارية (≤20 وسم، ≤30 حرف/وسم) |
| `account_credit` | decimal(14,2) | لا | `0.00` | تحكّم منصّة: رصيد دائن يُطبَّق على فاتورة الاشتراك التالية (≥ 0) |
| `payout_config` | json | نعم | — | تحكّم منصّة: إعدادات التسويات البنكية (تُستهلك في ملف 05) |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |
| `archived_at` | timestamp | نعم | — | أرشفة ناعمة (soft-archive؛ يقابل `archivedAt`) — الأرشفة تصاحبها عادةً `is_suspended` |

- **فهارس/قيود:** unique(`legacy_cuid`)، unique(`slug`)، unique(`custom_domain`) partial (حيث NOT NULL)، index(`is_suspended`)، index(`archived_at`)، CHECK(`account_credit` >= 0)، CHECK(`tax_rate` >= 0).
- **علاقات:** hasMany `branches`؛ hasOne اشتراك المنصّة (ملف 12 — خارج هذا المجال)؛ نطاق `tenantsOnly` يستثني org دفاتر المنصّة المحجوزة (لا global scope).
- **ملاحظة:** `feature_overrides`/`admin_tags`/`payout_config`/`settings` تبقى أعمدة JSON لأنها بيانات شبه-منظّمة/متفرّقة (أعمدة Prisma إضافية أصلاً، ليست من جدول Setting). كتالوج الخصائص نفسه مُنمذَج في `features`.

---

### `branches`  ← كان: `Branch`
> فرع تشغيلي تابع لمنشأة.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | فريد — cuid الفرع القديم |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` — onDelete **restrict** (لا حذف صلب لمنشأة) |
| `name` | string | لا | — | اسم الفرع |
| `code` | string | لا | — | رمز الفرع (uppercase)؛ الفرع الرئيسي دائماً `MAIN` |
| `address` | string | نعم | — | |
| `phone` | string | نعم | — | |
| `is_active` | boolean | لا | `true` | تعطيل ناعم؛ لا يُعطَّل آخر فرع نشط في المنشأة |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`organization_id`,`code`)، unique(`legacy_cuid`)، index(`organization_id`)، index(`organization_id`,`is_active`).
- **علاقات:** belongsTo `organizations`؛ hasMany `user_branches`؛ hasMany طلبات (خارج المجال).

---

### `users`  ← كان: `User`
> موظف منشأة و/أو أدمن منصّة (جدول واحد يخدم السطحين؛ يميَّز أدمن المنصّة بـ `is_platform_owner`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | فريد — cuid المستخدم القديم |
| `name` | string | لا | — | الاسم المعروض |
| `email` | string | لا | — | **فريد عالمياً** عبر كل المنشآت والأدمن |
| `phone` | string | نعم | — | للعرض/البحث |
| `password_hash` | string | لا | — | `$hidden`؛ هاش bcryptjs `$2b$`/`$2y$` (يُتحقق بـ `password_verify()`) |
| `role` | string | لا | — | الدور الأعلى المشتق: CHECK IN (`SUPER_ADMIN`,`BRANCH_MANAGER`,`CASHIER`,`RECEPTION`) |
| `is_active` | boolean | لا | `true` | التعطيل = رفض دخول + إبطال كل توكن فوراً (soft-delete flag) |
| `is_platform_owner` | boolean | لا | `false` | يفتح سطح `/admin/*` و`/platform/*` |
| `platform_role` | string | نعم | — | CHECK IN (`OWNER`,`SUPPORT`,`SALES`,`FINANCE`,`VIEWER`)؛ **null يُعامَل OWNER** |
| `last_login_at` | timestamp | نعم | — | آخر دخول ناجح (يُكتب بهدوء) |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`email`)، unique(`legacy_cuid`)، index(`is_platform_owner`)، index(`role`)، index(`is_active`).
- **علاقات:** hasMany `user_branches`؛ hasOne `user_permission_overrides`؛ hasMany `user_platform_permissions`. انتماء المنشأة **مشتق من الفروع** (لا عمود `organization_id` مباشر) — عن قصد.
- **ملاحظات:**
  - `role` **انعكاس** للأعلى امتيازاً عبر فروع المستخدم (مصدر الحقيقة = `user_branches.role`)؛ لا تعدّله مباشرةً.
  - **لا عمود `auth_version`:** بصمة كلمة المرور (`authVersion`) **تُشتق حساباً من `password_hash`** وقت التحقق ولا تُخزَّن — فتغيير كلمة المرور يُبطل كل توكنات staff/platform تلقائياً.
  - **لا `deleted_at`:** الحذف الناعم للمستخدم عبر `is_active=false` (يحتفظ بصفوف `user_branches`، ولا يستهلك مقعداً).

---

### `user_branches`  ← كان: `UserBranch`
> الرابط بين المستخدم والفرع، **ومصدر الدور الحقيقي لكل فرع**.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | فريد — cuid العضوية القديم |
| `user_id` | bigint unsigned | لا | — | FK → `users.id` — onDelete **cascade** |
| `branch_id` | bigint unsigned | لا | — | FK → `branches.id` — onDelete **cascade** |
| `role` | string | لا | — | الدور داخل هذا الفرع: CHECK IN (`SUPER_ADMIN`,`BRANCH_MANAGER`,`CASHIER`,`RECEPTION`) |

- **فهارس/قيود:** unique(`user_id`,`branch_id`)، index(`branch_id`)، index(`user_id`).
- **بلا طوابع:** `timestamps=false` (مطابقةً لـ Prisma).
- **علاقات:** belongsTo `users`، belongsTo `branches`.
- **ملاحظة:** فحص العضوية الحيّ في الميدل وير يعدّ صفوف هذا الجدول ضمن فروع منشأة التوكن؛ اختفاء كل العضويات → رفض الطلب (401).

---

### `user_permission_overrides`  ← جديد — محوّل من Setting key `user.permissions:{userId}`
> رأس تجاوز صلاحيات موظف فردي. **وجود الصف = تجاوز صريح فعّال** (يستبدل افتراضي الدور بالكامل، حتى لو صفر بنود).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `user_id` | bigint unsigned | لا | — | FK → `users.id` — onDelete **cascade** |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`user_id`).
- **الحالات الثلاث (تُطابق `applyOverride`):** لا صف = افتراضي الدور؛ صف بلا بنود = تجاوز صريح فارغ (يمنع كل شيء)؛ صف + بنود = التجاوز الصريح. «مسح التجاوز» = حذف الصف.

### `user_permission_override_items`  ← بنود التجاوز أعلاه
> مفتاح صلاحية واحد ممنوح ضمن التجاوز.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `user_permission_override_id` | bigint unsigned | لا | — | FK → `user_permission_overrides.id` — onDelete **cascade** |
| `permission` | string | لا | — | CHECK IN كتالوج صلاحيات الموظف (11 مفتاحاً، انظر أدناه) |

- **فهارس/قيود:** unique(`user_permission_override_id`,`permission`).
- **كتالوج صلاحيات الموظف (11):** `users.manage`, `settings.manage`, `pos.checkout`, `orders.manage`, `customers.manage`, `catalog.manage`, `catalog.read`, `catalog.manageCodes`, `shifts.manage`, `reports.view`, `accounting.view`.

---

### `user_platform_permissions`  ← جديد — محوّل من عمود `User.platformPermissions` (Postgres `text[]`)
> صلاحيات أدمن المنصّة الدقيقة الممنوحة صراحةً (تقيّد الأدوار غير OWNER).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `user_id` | bigint unsigned | لا | — | FK → `users.id` — onDelete **cascade** |
| `permission` | string | لا | — | CHECK IN كتالوج صلاحيات المنصّة (15 مفتاحاً، انظر أدناه) |

- **فهارس/قيود:** unique(`user_id`,`permission`)، index(`user_id`).
- **كتالوج صلاحيات المنصّة (15):** `manage_tenants`, `manage_subscriptions`, `manage_plans`, `manage_admins`, `manage_crm`, `manage_leads`, `manage_accounting`, `manage_support`, `manage_marketing`, `manage_announcements`, `manage_config`, `view_finance`, `manage_partners`, `manage_whatsapp`, `manage_payouts`.
- **ملاحظة:** OWNER يتجاوز الكل ضمنياً (لا يحتاج صفوفاً)؛ VIEWER = صفر صفوف. الأدوار الخمسة نفسها نصوص على `users.platform_role` (لا جدول مستقل — إنها ثابتة `PlatformAccess::ROLES`).

---

### `admin_role_presets`  ← جديد — محوّل من PlatformConfig key `platform.customRoles`
> تشكيلات صلاحيات أدمن منصّة مُسمّاة قابلة لإعادة الاستخدام (تُطبَّق على `user_platform_permissions` عند إسنادها). مُدارة عبر `manage_admins`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `key` | string | لا | — | فريد — معرّف التشكيلة |
| `name` | string | لا | — | الاسم المعروض |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`key`).

### `admin_role_preset_permissions`  ← بنود التشكيلة أعلاه
| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `admin_role_preset_id` | bigint unsigned | لا | — | FK → `admin_role_presets.id` — onDelete **cascade** |
| `permission` | string | لا | — | CHECK IN كتالوج صلاحيات المنصّة الـ15 |

- **فهارس/قيود:** unique(`admin_role_preset_id`,`permission`).

---

### `token_denylist`  ← جديد — محوّل من الكاش الملفّي `TokenDenylist`
> قائمة إبطال التوكنات (jti) قبل انتهائها الطبيعي: تسجيل الخروج، الإلغاء القسري، وحرق إثبات pos-otp الذرّي قبل تحرّك المال (`reserve`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `jti` | string | لا | — | فريد — معرّف التوكن (12 بايت hex = 24 حرفاً) |
| `user_id` | bigint unsigned | نعم | — | FK → `users.id` — onDelete **cascade** (لتوكنات staff/platform؛ null لغيرها) |
| `kind` | string | نعم | — | CHECK IN (`staff`,`platform`,`customer`,`supplier`,`affiliate`,`driver`,`pos-otp`) |
| `reason` | string | نعم | — | CHECK IN (`logout`,`forced`,`reserve`,`impersonation_stop`) |
| `expires_at` | timestamp | لا | — | زمن `exp` الأصلي للتوكن — يُحذف الصف بعده (تقليم) |
| `created_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`jti`)، index(`expires_at`) (للتقليم الدوري)، index(`user_id`).
- **ملاحظة تزامن:** `reserve($claims)` لإثبات pos-otp = إدراج ذرّي مشروط على unique(`jti`) — يحرق المعرّف **قبل** تحرّك المال (يمنع سباق الإعادة → خصم مضاعف). في التصميم الجدولي يُنفَّذ بـ `INSERT ... ON CONFLICT (jti) DO NOTHING` وفحص عدد الصفوف المتأثرة.

---

### `security_logs`  ← جديد — محوّل من AuditLog (`LOGIN_OK`/`LOGIN_FAILED`)
> سجلّ أحداث المصادقة لحساب القفل بالنطاق `(البريد + IP)` بعد المحاولات الفاشلة.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `user_id` | bigint unsigned | نعم | — | FK → `users.id` — onDelete **set null** (الفشل بلا مستخدم) |
| `email` | string | نعم | — | البريد المُحاوَل (للعدّ الآمن من التعداد) |
| `surface` | string | لا | — | سطح الدخول: CHECK IN (`staff`,`admin`,`supplier`,`customer`,`affiliate`,`driver`) |
| `ip_address` | string(45) | نعم | — | IPv4/IPv6 |
| `action` | string | لا | — | CHECK IN (`LOGIN_OK`,`LOGIN_FAILED`) |
| `reason` | string | نعم | — | سبب الفشل (`bad_credentials`,`not_active`,`not_owner`,`rejected`…) |
| `user_agent` | string | نعم | — | |
| `created_at` | timestamp | لا | — | |

- **فهارس/قيود:** index(`email`,`ip_address`,`created_at`) (نافذة عدّ القفل)، index(`user_id`)، index(`created_at`).
- **منطق القفل (تطبيقي، من هذه الصفوف):** عدّ `LOGIN_FAILED` ضمن `windowMinutes` بنطاق `(email + ip_address)` منذ **آخر** `LOGIN_OK`؛ إن ≥ `maxAttempts` (10) → حظر `lockoutMinutes` (15) من آخر فشل. الدخول الناجح يمسح العدّاد. النطاق بالـIP (لا البريد وحده) لأن مالك المنصّة الوحيد بلا مسار لإعادة تعيين كلمة المرور.

---

### `features`  ← جديد — محوّل من `FeatureRegistry` (كود ثابت)
> كتالوج الخصائص المتحكَّم بها — المصدر الوحيد الذي يقود مفاتيح لوحة الأدمن، مفاتيح الخطط، وبوّابة `requireFeature()`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `key` | string | لا | — | فريد — مفتاح الخاصية (`pos`, `delivery`, `loyalty`…) |
| `name` | string | لا | — | التسمية العربية |
| `category` | string | لا | — | CHECK IN (`core`,`operations`,`growth`,`finance`) |
| `is_core` | boolean | لا | `false` | core = دائماً مفعّلة، غير gated (لا تُعطَّل أبداً) |
| `sort_order` | int | لا | `0` | ترتيب العرض |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`key`)، index(`category`).
- **البيانات المرجعية (تُبذَر seed):**
  - **core** (6): `pos`, `orders`, `customers`, `catalog`, `shift`, `settings`.
  - **operations** (3): `delivery`, `inventory`, `contracts`.
  - **growth** (7): `loyalty`, `subscriptions`, `portal`, `portal_offers`, `messaging`, `branding`, `supplier_market`.
  - **finance** (2): `analytics`, `reports_export`.
- **علاقة الاستحقاقات:** خطة المنصّة تخزّن `featureKeys` تشير إلى `features.key` (الخطط في ملف 12)؛ `organizations.feature_overrides` تفرض on/off على مفاتيح gated (`is_core=false`) فقط.

---

## تحويلات من Setting-JSON

| مفتاح Setting/Config القديم | المصير في هذا المجال | السبب |
|---|---|---|
| `user.permissions:{userId}` (Setting JSON) | → **جدول حقيقي** `user_permission_overrides` (+ `_items`) | تجاوز صلاحيات منظّم بحالة ثلاثية (غائب/فارغ/مصفوفة)؛ الرأس+البنود يمثّلان «الفارغ» بأمانة |
| `User.platformPermissions` (Postgres `text[]`) | → **جدول حقيقي** `user_platform_permissions` | مجموعة مفاتيح enum منظّمة؛ تُطبَّق CHECK وفهرس فريد |
| `platform.customRoles` (PlatformConfig JSON) | → **جدول حقيقي** `admin_role_presets` (+ `_permissions`) | تشكيلات صلاحيات مُسمّاة قابلة للاستعلام (أُدرِجت لقرابتها بالهوية) |
| `Organization.feature_overrides` / `admin_tags` / `payout_config` / `settings` | تبقى **أعمدة JSON** على `organizations` | أعمدة Prisma إضافية شبه-منظّمة/متفرّقة (ليست من Setting)؛ الكتالوج نفسه مُنمذَج في `features` |
| `hr.cost:{userId}` (Setting JSON) | **خارج هذا المجال** — يخصّ مجال المنشأة/HR (تكاليف الموظفين، `OrganizationController`) | مالي-تشغيلي، لا هوية |
| Cache `TokenDenylist` (ملفّي) | → **جدول** `token_denylist` (بديل مقترح للتصميم الجدولي) | ثبات الإبطال + الحرق الذرّي عبر unique(`jti`) بدل ذاكرة متطايرة |
| AuditLog `LOGIN_OK/LOGIN_FAILED` | → **جدول** `security_logs` مخصّص | فهرسة نافذة القفل `(email+ip+created_at)` دون تلويث سجلّ التدقيق العام |
