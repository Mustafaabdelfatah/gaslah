# مخطط الجداول — مجال المنصة والفوترة (02)

> إعادة تصميم علائقية مُطبَّعة (snake_case / bigint PK) لكيانات **إدارة المنصة، اشتراكاتها، فوترتها، الأجهزة، الشركاء، الأحداث، الإعلانات، التسجيل، و Dunning** المستخرجة من `../12-platform-tenants.md`.
> يفترض أن جداول `organizations` / `branches` / `users` مُعاد تصميمها في ملف `01-auth-roles-tenancy` بنفس الاصطلاح (bigint PK)، وأن هذا الملف يشير إليها كمراجع FK.
> **لا يشمل**: محاسبة المنشأة الداخلية (ملف 08) ولا التسويات البنكية (ملف 05). القيود المحاسبية لدفاتر المنصة تُذكر في قسم [دفاتر المنصة](#دفاتر-المنصة) لأنها تُخزَّن على نفس جداول المحاسبة لكن لمنشأة نظام محجوزة.

## الاصطلاحات المطبّقة في كل الجداول

- كل جدول: `id` bigint auto-increment PK؛ `legacy_cuid` VARCHAR nullable **UNIQUE** (يحمل الـ cuid القديم عند الاستيراد من Prisma، وإلا null للصفوف الجديدة).
- FK باسم `{singular}_id`؛ المملوك لمنشأة يحمل `organization_id`، والمربوط بفرع يحمل `branch_id`.
- `created_at` / `updated_at` في كل جدول (حتى لو كان أصل Prisma بلا `updatedAt` — نُوحّد الطوابع)؛ `deleted_at` للحذف الناعم حيث ينطبق.
- أموال: `DECIMAL(14,2)`. نِسَب مئوية: `DECIMAL(5,2)`.
- الحالات/الأنواع: عمود `VARCHAR` + PHP `enum` + قيد `CHECK` بقائمة القيم المذكورة صراحةً تحت كل جدول.
- JSON فقط لِـ metadata / snapshots / بُنى إعدادات عميقة تُحرَّر ككتلة. أي Setting-JSON منظّم ومُستعلَم عنه → أعمدة (انظر قسم [تحويلات من Setting-JSON](#تحويلات-من-setting-json)).

---

## أولاً: الخطط والاشتراكات

### `platform_plans`  ← كان: `PlatformPlan`
> باقات المنصة التي تبيعها المنصة للمنشآت (مميّزة عن `subscription_plans` وهي باقة عميل داخل المنشأة).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE — cuid القديم |
| `name` | varchar(120) | لا | — | الاسم العربي |
| `name_en` | varchar(120) | نعم | null | الاسم الإنجليزي |
| `monthly_price` | decimal(14,2) | لا | 0 | شامل الضريبة |
| `yearly_price` | decimal(14,2) | لا | 0 | شامل الضريبة |
| `max_branches` | int | لا | 1 | حد الفروع |
| `max_users` | int | لا | 1 | حد المستخدمين |
| `features` | jsonb | لا | `[]` | نصوص عرض تسويقية (كان `text[]`) |
| `feature_keys` | jsonb | لا | `[]` | مفاتيح الاستحقاق الفعلية من `FeatureRegistry` (كان `text[]`) |
| `is_popular` | boolean | لا | false | وسم «الأكثر شيوعاً» |
| `sort_order` | int | لا | 0 | ترتيب العرض/اختيار «الأرخص» |
| `is_active` | boolean | لا | true | التقاعد = false (لا حذف صلب) |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | حذف ناعم |

- **فهارس/قيود:** UNIQUE(`legacy_cuid`)؛ INDEX(`is_active`, `sort_order`) لاختيار أرخص خطة نشطة بسرعة. `features`/`feature_keys` مصفوفات نصّية — تبقى JSON (قوائم قيم، لا استعلام حقلي عليها).
- **علاقات:** hasMany `platform_subscriptions`، hasMany `platform_coupons` (عبر `applies_to_plan_id`).

### `platform_subscriptions`  ← كان: `PlatformSubscription`
> اشتراك منشأة واحدة في المنصة. **صف واحد لكل منشأة**.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **RESTRICT** |
| `plan_id` | bigint | نعم | null | FK → `platform_plans.id` · onDelete **RESTRICT** |
| `cycle` | varchar(10) | لا | `MONTHLY` | enum |
| `status` | varchar(12) | لا | `TRIAL` | enum |
| `price` | decimal(14,2) | لا | 0 | السعر الفعلي المدفوع (بعد الكوبون؛ 0 في TRIAL) |
| `started_at` | timestamptz | لا | now | |
| `current_period_end` | timestamptz | لا | — | نهاية الفترة الحالية |
| `cancel_at_period_end` | boolean | لا | false | |
| `canceled_at` | timestamptz | نعم | null | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |

- **enum `cycle`** (CHECK): `MONTHLY` · `YEARLY`.
- **enum `status`** (CHECK): `TRIAL` · `ACTIVE` · `PAST_DUE` · `CANCELLED`.
  > `EXPIRED` **حالة مشتقّة لا تُخزَّن** (`TRIAL`/`ACTIVE` بعد مضي `current_period_end`) — تُحسب في طبقة العرض، لا عمود لها.
  > **غياب الصف** = منشأة grandfathered (نشطة بالكامل، حدود لانهائية) — لا يُمثَّل بحالة بل بعدم وجود صف.
- **فهارس/قيود:** **UNIQUE(`organization_id`)** (صف واحد لكل منشأة، يدعم `updateOrCreate`)؛ INDEX(`status`, `current_period_end`) لدورة Dunning ومسح المنتهية.
- **علاقات:** belongsTo `organizations`, belongsTo `platform_plans`, hasMany `subscription_invoices`.

### `org_add_ons`  ← كان: `OrgAddOn`
> خاصية مدفوعة إضافية مُفعَّلة لمنشأة **فوق خطتها** (مثل `delivery`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **CASCADE** |
| `key` | varchar(60) | لا | — | مفتاح الإضافة (`delivery` / `portal_offers` / `supplier_market`) |
| `is_active` | boolean | لا | true | |
| `price_monthly` | decimal(14,2) | لا | 0 | |
| `activated_at` | timestamptz | لا | now | |
| `expires_at` | timestamptz | نعم | null | انتهاء الإضافة (null = دائمة) |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |

- **فهارس/قيود:** **UNIQUE(`organization_id`, `key`)** (إضافة واحدة لكل مفتاح لكل منشأة)؛ INDEX(`is_active`, `expires_at`) لحساب الاستحقاق (الإضافة النشطة غير المنتهية فقط تُحتسب، وفقط إن كان الاشتراك active).
- **علاقات:** belongsTo `organizations`.

### `platform_coupons`  ← كان: `PlatformCoupon`
> كوبون خصم على اشتراكات المنشآت، يُستهلَك عند تحديث الاشتراك.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `code` | varchar(40) | لا | — | uppercase، UNIQUE، regex `^[A-Za-z0-9_-]+$` (2..40) |
| `type` | varchar(12) | لا | — | enum |
| `value` | decimal(14,2) | لا | 0 | للـ PERCENT محدود 0..100 (يُنفَّذ في التطبيق) |
| `max_redemptions` | int | نعم | null | null = لا حد |
| `redemptions` | int | لا | 0 | عدّاد مُستهلَك (تحديث ذرّي مشروط) |
| `applies_to_plan_id` | bigint | نعم | null | FK → `platform_plans.id` · onDelete **SET NULL** — يقيّد الكوبون بخطة |
| `expires_at` | timestamptz | نعم | null | |
| `is_active` | boolean | لا | true | |
| `note` | varchar(255) | نعم | null | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **enum `type`** (CHECK): `PERCENT` (سعر × (1−value%/100)) · `FIXED` (max(0, سعر − value)) · `FREE_MONTHS` (لا يغيّر السعر، يمدّد الفترة بـ (int)value شهراً).
- **فهارس/قيود:** UNIQUE(`code`)؛ INDEX(`is_active`, `expires_at`). قيد المنطق: `redemptions <= max_redemptions` يُفرَض بتحديث ذرّي مشروط (`WHERE redemptions < max_redemptions`) لا بـ CHECK ثابت.
- **علاقات:** belongsTo `platform_plans` (اختياري).

---

## ثانياً: الفوترة والأجهزة

### `subscription_invoices`  ← كان: `SubscriptionInvoice`
> فاتورة ضريبية ZATCA يصدرها مشغّل الـ SaaS مقابل اشتراك منشأة. سلسلة ICV/PIH ببادئة **`SUB-`**.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **RESTRICT** |
| `subscription_id` | bigint | نعم | null | FK → `platform_subscriptions.id` · onDelete **SET NULL** |
| `charge_id` | varchar(60) | نعم | null | ربط برسم `OnlineCharge` (خارج المجال — ملف 05) |
| `invoice_no` | varchar(40) | نعم | null | يُخصَّص عند الاعتماد (ISSUED) فقط |
| `seller_name` | varchar(160) | نعم | null | لقطة البائع (المشغّل) |
| `seller_vat` | varchar(20) | نعم | null | |
| `buyer_name` | varchar(160) | نعم | null | لقطة المشتري (المنشأة) |
| `buyer_vat` | varchar(20) | نعم | null | |
| `plan_name` | varchar(120) | نعم | null | لقطة اسم الخطة |
| `cycle` | varchar(10) | نعم | null | لقطة الدورة |
| `subtotal` | decimal(14,2) | لا | 0 | صافي قبل الضريبة |
| `vat` | decimal(14,2) | لا | 0 | ضريبة (15/115) |
| `total` | decimal(14,2) | لا | 0 | شامل الضريبة |
| `payment_method` | varchar(16) | نعم | null | enum |
| `bank_name` | varchar(120) | نعم | null | مطلوب للتحويل |
| `transfer_ref` | varchar(120) | نعم | null | مطلوب للتحويل |
| `gateway_name` | varchar(60) | نعم | null | مطلوب للبوابة |
| `icv` | bigint | نعم | null | عدّاد فاتورة تسلسلي (سلسلة `SUB-`) — يُخصَّص عند ISSUED |
| `pih` | text | نعم | null | hash الفاتورة السابقة (Previous Invoice Hash) |
| `hash` | text | نعم | null | SHA256 canonical |
| `qr` | text | نعم | null | QR (tags 1..6) |
| `status` | varchar(8) | لا | `DRAFT` | enum |
| `confirmed_at` | timestamptz | نعم | null | لحظة الاعتماد |
| `confirmed_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `issued_at` | timestamptz | نعم | null | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |

- **enum `status`** (CHECK): `DRAFT` (مسوّدة بلا سلسلة/بلا قيد، تُحذف بحرّية) · `ISSUED` (فاتورة ZATCA غير قابلة للتعديل + قيد إيراد).
- **enum `payment_method`** (CHECK): `CASH` · `BANK_TRANSFER` · `GATEWAY`.
- **فهارس/قيود:** **UNIQUE(`icv`)** جزئي `WHERE status='ISSUED'` (السلسلة تتقدّم فوق المعتمَد فقط، المسوّدات لا تحمل سلوتاً)؛ **UNIQUE(`invoice_no`)** جزئي `WHERE invoice_no IS NOT NULL`؛ UNIQUE(`charge_id`) جزئي `WHERE charge_id IS NOT NULL` (منع فوترة نفس الرسم مرّتين)؛ INDEX(`organization_id`, `status`).
- **علاقات:** belongsTo `organizations`, belongsTo `platform_subscriptions`, belongsTo `users` (confirmed_by).

### `platform_devices`  ← كان: `PlatformDevice`
> كتالوج أجهزة تبيعها المنصة (جهاز POS، طابعة فواتير…). يعبّئ سطور فاتورة بيع الجهاز مسبقاً.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `name` | varchar(160) | لا | — | |
| `sku` | varchar(60) | نعم | null | UNIQUE جزئي |
| `price` | decimal(14,2) | لا | 0 | سعر الوحدة **شامل الضريبة** |
| `is_active` | boolean | لا | true | |
| `sort_order` | int | لا | 0 | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **فهارس/قيود:** UNIQUE(`sku`) جزئي `WHERE sku IS NOT NULL`؛ INDEX(`is_active`, `sort_order`).
- **علاقات:** لا FK صادر (كتالوج مرجعي). السطور تُلتقط snapshot في `device_sales.items`.

### `device_sales`  ← كان: `DeviceSale`
> فاتورة ضريبية ZATCA لبيع أجهزة لمنشأة أو مشترٍ خارجي. سلسلة ICV/PIH ببادئة **`DEV-`** منفصلة تماماً عن `SUB-`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | نعم | null | FK → `organizations.id` · onDelete **RESTRICT** — null = مشترٍ خارجي |
| `invoice_no` | varchar(40) | نعم | null | يُخصَّص عند ISSUED |
| `buyer_name` | varchar(160) | نعم | null | لمشترٍ خارجي أو لقطة اسم المنشأة |
| `buyer_vat` | varchar(20) | نعم | null | |
| `seller_name` | varchar(160) | نعم | null | |
| `seller_vat` | varchar(20) | نعم | null | |
| `items` | jsonb | لا | `[]` | لقطة السطور `[{name, qty, unit_price, line_total}]` |
| `notes` | text | نعم | null | |
| `subtotal` | decimal(14,2) | لا | 0 | |
| `vat` | decimal(14,2) | لا | 0 | 15/115 |
| `total` | decimal(14,2) | لا | 0 | شامل الضريبة |
| `payment_method` | varchar(16) | نعم | null | enum (نفس قيم فاتورة الاشتراك) |
| `bank_name` | varchar(120) | نعم | null | |
| `transfer_ref` | varchar(120) | نعم | null | |
| `gateway_name` | varchar(60) | نعم | null | |
| `icv` | bigint | نعم | null | سلسلة `DEV-` |
| `pih` | text | نعم | null | |
| `hash` | text | نعم | null | |
| `qr` | text | نعم | null | |
| `status` | varchar(8) | لا | `DRAFT` | enum `DRAFT` / `ISSUED` |
| `confirmed_at` | timestamptz | نعم | null | |
| `confirmed_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `issued_at` | timestamptz | نعم | null | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |

- **enum `status`** (CHECK): `DRAFT` · `ISSUED`. **enum `payment_method`** (CHECK): `CASH` · `BANK_TRANSFER` · `GATEWAY`.
- **فهارس/قيود:** **UNIQUE(`icv`)** جزئي `WHERE status='ISSUED'` (سلسلة `DEV-` مستقلّة)؛ UNIQUE(`invoice_no`) جزئي؛ INDEX(`organization_id`, `status`). قاعدة عمل: يُمنَع إصدار فاتورة لمنشأة «دفاتر المنصة» المحجوزة (يُفرَض في التطبيق، 422).
- **علاقات:** belongsTo `organizations` (اختياري), belongsTo `users` (confirmed_by).

---

## ثالثاً: مالية المنصة والشركاء

### `platform_partners`  ← كان: `PlatformPartner`
> شريك مؤسِّس — ملكية أسهم + حصة أرباح على دفاتر المنصة. **حسّاس** (كل الوصول عبر `manage_partners`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `name` | varchar(160) | لا | — | |
| `role` | varchar(80) | نعم | null | دور/صفة الشريك |
| `email` | varchar(160) | نعم | null | |
| `ownership_percent` | decimal(5,2) | لا | 0 | نسبة الملكية (سقف إجمالي للنشطين 100%) |
| `joined_at` | timestamptz | نعم | null | |
| `is_active` | boolean | لا | true | غير النشط يساهم بـ 0 |
| `notes` | text | نعم | null | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **فهارس/قيود:** INDEX(`is_active`). سقف الملكية (Σ `ownership_percent` للنشطين ≤ 100) يُفرَض بقفل `pg_advisory_xact_lock` حول القراءة+الكتابة — لا CHECK جدولي (قيد عبر-صفوف).
- **علاقات:** hasMany `platform_partner_distributions`, hasMany `platform_expenses` (عبر `paid_by_partner_id`).

### `platform_partner_distributions`  ← كان: `PlatformPartnerDistribution`
> توزيع نقدي مدفوع لشريك مقابل حصته. الصف + قيد Dr Drawings/Cr Bank ذرّيان.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `partner_id` | bigint | لا | — | FK → `platform_partners.id` · onDelete **RESTRICT** (حفظ التاريخ المالي) |
| `amount` | decimal(14,2) | لا | 0 | |
| `date` | date | لا | — | تاريخ التوزيع |
| `note` | varchar(255) | نعم | null | |
| `recorded_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |

- **فهارس/قيود:** INDEX(`partner_id`, `date`).
- **علاقات:** belongsTo `platform_partners`, belongsTo `users` (recorded_by).

### `platform_expenses`  ← كان: `PlatformExpense`
> مصروف تشغيلي للمنصة (تسويق، رواتب، استضافة…) لقائمة دخل الـ SaaS، مع دعم تمويل شريك.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `date` | date | لا | — | |
| `category` | varchar(80) | لا | — | فئة المصروف |
| `amount` | decimal(14,2) | لا | 0 | |
| `note` | varchar(255) | نعم | null | |
| `created_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `paid_by_partner_id` | bigint | نعم | null | FK → `platform_partners.id` · onDelete **SET NULL** — الشريك الذي موّل المصروف |
| `reimbursed_at` | timestamptz | نعم | null | سداد ذرّي (compare-and-swap) |
| `reimbursed_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |

- **فهارس/قيود:** INDEX(`date`, `category`)؛ INDEX(`paid_by_partner_id`, `reimbursed_at`) لاستعلام «المصروفات الممولة من شريك غير المسدَّدة». السداد أوّل POST فقط ينجح عبر تحديث مشروط `WHERE reimbursed_at IS NULL`.
- **علاقات:** belongsTo `users` (created_by/reimbursed_by), belongsTo `platform_partners` (paid_by_partner).

---

## رابعاً: الأحداث والإعلانات والوثائق

### `platform_events`  ← كان: `PlatformEvent`
> سجل أحداث دورة حياة الاشتراك (مصدر حساب MRR وشلّال الحركة).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **CASCADE** |
| `type` | varchar(20) | لا | — | enum |
| `plan_name` | varchar(120) | نعم | null | لقطة |
| `cycle` | varchar(10) | نعم | null | لقطة |
| `monthly` | decimal(14,2) | نعم | null | MRR الشهري المُسنَد للحدث |
| `amount` | decimal(14,2) | نعم | null | قيمة الحدث |
| `created_at` | timestamptz | لا | now | |

- **enum `type`** (CHECK): `SIGNUP` · `TRIAL_START` · `TRIAL_CONVERT` · `RENEW` · `PLAN_CHANGE` · `EXTEND` · `CANCEL_SCHEDULED` · `REACTIVATE` · `SUSPEND` · `EXPIRE`.
  > تصنيف MRR: churn = {`CANCEL_SCHEDULED`, `SUSPEND`, `EXPIRE`}؛ new-MRR = {`SIGNUP`, `TRIAL_CONVERT`}.
- **فهارس/قيود:** INDEX(`organization_id`, `created_at`)؛ INDEX(`type`, `created_at`) لشلّال MRR الشهري. جدول append-only (لا `updated_at`/`deleted_at`).
- **علاقات:** belongsTo `organizations`.

### `platform_announcements`  ← كان: `PlatformAnnouncement` (وضع البثّ)
> لافتة بثّ platform → **كل المستأجرين** تظهر داخل لوحات المنشآت. (الرسائل الموجَّهة لمنشأة واحدة انفصلت إلى `org_announcements`.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `title` | varchar(200) | لا | — | |
| `body` | text | نعم | null | |
| `level` | varchar(10) | لا | `INFO` | enum |
| `is_active` | boolean | لا | true | |
| `starts_at` | timestamptz | نعم | null | نافذة العرض |
| `ends_at` | timestamptz | نعم | null | |
| `created_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **enum `level`** (CHECK): `INFO` · `WARNING` · `CRITICAL` (تنبيه صيانة).
- **فهارس/قيود:** INDEX(`is_active`, `starts_at`, `ends_at`) لجلب النشط ضمن النافذة.
- **علاقات:** belongsTo `users` (created_by).
  > **ملاحظة تصميم:** في Prisma الأصلي كان `PlatformAnnouncement.orgId` nullable (null=للكل، قيمة=منشأة واحدة). فصلناه: هذا الجدول للبثّ العام فقط، والموجَّه لمنشأة في `org_announcements` — لتفادي عمود nullable مزدوج المعنى ولتقييد FK بوضوح.

### `org_announcements`  ← كان: `PlatformAnnouncement` (الموجَّه لمنشأة) + رسالة `AdminTenantDetailController::message`
> إعلان/رسالة موجَّهة لمنشأة **واحدة** تظهر داخل لوحتها (control-center message + اللافتات المُقيَّدة بمنشأة).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **CASCADE** |
| `title` | varchar(200) | لا | — | |
| `body` | text | نعم | null | |
| `level` | varchar(10) | لا | `INFO` | enum `INFO`/`WARNING`/`CRITICAL` |
| `is_active` | boolean | لا | true | |
| `starts_at` | timestamptz | نعم | null | |
| `ends_at` | timestamptz | نعم | null | |
| `whatsapp_sent` | boolean | لا | false | هل أُرسلت نسخة واتساب لأدمن المنشأة |
| `created_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **enum `level`** (CHECK): `INFO` · `WARNING` · `CRITICAL`.
- **فهارس/قيود:** INDEX(`organization_id`, `is_active`, `starts_at`, `ends_at`).
- **علاقات:** belongsTo `organizations`, belongsTo `users` (created_by).

### `org_documents`  ← كان: `OrgDocument`
> مستند أعمال (سجل تجاري، شهادة ضريبة، رخصة…) مرفق بملف المنشأة. يُخدَم عبر رابط موقّع مؤقّت.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **CASCADE** |
| `name` | varchar(200) | لا | — | |
| `path` | varchar(500) | لا | — | مسار التخزين |
| `mime_type` | varchar(120) | نعم | null | |
| `size` | bigint | نعم | null | بالبايت |
| `uploaded_by_id` | bigint | نعم | null | FK → `users.id` · onDelete **SET NULL** |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **فهارس/قيود:** INDEX(`organization_id`).
- **علاقات:** belongsTo `organizations`, belongsTo `users` (uploaded_by).

---

## خامساً: التسجيل، الإعدادات، و Dunning

### `leads`  ← كان: `Lead`
> عميل محتمل (prospect) في مسار المبيعات، يُحوَّل إلى منشأة عبر `provision()`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | null | UNIQUE |
| `business_name` | varchar(200) | لا | — | يصبح اسم المنشأة عند التحويل |
| `contact_name` | varchar(160) | نعم | null | |
| `email` | varchar(160) | نعم | null | |
| `phone` | varchar(30) | نعم | null | |
| `source` | varchar(80) | نعم | null | قناة الوصول |
| `stage` | varchar(12) | لا | `NEW` | enum |
| `note` | text | نعم | null | |
| `owner_id` | bigint | نعم | null | FK → `users.id` (أدمن المبيعات المسؤول) · onDelete **SET NULL** |
| `converted_organization_id` | bigint | نعم | null | FK → `organizations.id` · onDelete **SET NULL** — يُضبَط عند WON |
| `won_at` | timestamptz | نعم | null | |
| `created_at` | timestamptz | لا | now | |
| `updated_at` | timestamptz | لا | now | |
| `deleted_at` | timestamptz | نعم | null | |

- **enum `stage`** (CHECK): `NEW` · `CONTACTED` · `QUALIFIED` · `PROPOSAL` · `WON` · `LOST`.
- **فهارس/قيود:** INDEX(`stage`, `created_at`)؛ UNIQUE(`converted_organization_id`) جزئي `WHERE converted_organization_id IS NOT NULL` (منشأة واحدة لكل lead — التحويل يُرفَض إن كان مضبوطاً).
- **علاقات:** belongsTo `users` (owner), belongsTo `organizations` (converted).

### `platform_settings`  ← كان: `PlatformConfig` (المفاتيح الثابتة المُهيكَلة)
> صف مفرد (singleton, `id=1`) يحمل الإعدادات القياسية المُستعلَم عنها كثيراً كأعمدة مُصنَّفة، بدل مفاتيح JSON مبعثرة. (البُنى العميقة المتغيّرة تبقى في `platform_configs` أدناه.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | smallint | لا | 1 | PK ثابت = 1 (CHECK `id = 1`) |
| `trial_days` | int | لا | 14 | مدة التجربة الافتراضية |
| `allow_public_signup` | boolean | لا | false | قفل التسجيل الذاتي |
| `seller_name` | varchar(160) | نعم | null | بيانات البائع (المشغّل) لفواتير ZATCA |
| `seller_vat` | varchar(20) | نعم | null | |
| `seller_cr` | varchar(40) | نعم | null | |
| `seller_address` | varchar(255) | نعم | null | |
| `platform_books_organization_id` | bigint | نعم | null | FK → `organizations.id` · onDelete **RESTRICT** — منشأة «دفاتر المنصة» المحجوزة |
| `dunning_enabled` | boolean | لا | false | تشغيل دورة Dunning |
| `dunning_remind_days_before` | jsonb | لا | `[3]` | قائمة أيام التذكير قبل التجديد |
| `dunning_remind_days_after` | jsonb | لا | `[3,7]` | قائمة أيام التذكير بعد الاستحقاق |
| `dunning_grace_days` | int | لا | 14 | فترة السماح قبل التعليق |
| `dunning_channel_whatsapp` | boolean | لا | true | |
| `dunning_channel_email` | boolean | لا | true | |
| `settings` | jsonb | لا | `{}` | حزمة `PlatformSettings` المُجمَّعة العميقة (general/security/health/…) — snapshot إعدادات UI تُحرَّر ككتلة، تبقى JSON عمداً |
| `updated_at` | timestamptz | لا | now | |

- **فهارس/قيود:** PK وحيد `id=1` (CHECK). قوائم أيام Dunning تبقى JSON (مصفوفات أرقام قصيرة، لا استعلام حقلي).
- **علاقات:** belongsTo `organizations` (platform_books).
  > **لماذا هجين؟** الفلاغات القياسية (`trial_days`, `allow_public_signup`, بيانات البائع، سياسة Dunning) تُستعلَم/تُفحَص في كل طلب تقريباً → أعمدة مُصنَّفة. أما مجموعات `PlatformSettings` الخمس عشرة العميقة والمتداخلة (تُحرَّر وتُقرأ ككتلة واحدة في مركز الإعدادات) فتبقى JSON — هي فعلاً snapshot إعداد لا صفوف بيانات مُستعلَمة.

### `platform_configs`  ← كان: `PlatformConfig` (الباقي غير المُهيكَل)
> مخزن key/value المتبقّي للبُنى الديناميكية التي لا تستحقّ أعمدة (الأدوار المخصّصة، لقطات إعدادات مجمّعة قديمة). يبقى لمرونة الترحيل.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `key` | varchar(120) | لا | — | UNIQUE — المفتاح المنطقي |
| `value` | jsonb | لا | `{}` | القيمة |
| `updated_at` | timestamptz | لا | now | |

- **مفاتيح متوقّعة:** `platform.customRoles` (أدوار الأدمن المخصّصة)، لقطات/مجموعات إعداد إضافية غير مُرقّاة لأعمدة. (المفاتيح القياسية `platform` / `platform.settings` / `platform.dunning` / `platformBooks` هُجِّرت إلى أعمدة `platform_settings`.)
- **فهارس/قيود:** UNIQUE(`key`).
- **علاقات:** لا شيء.

### `dunning_logs`  ← جديد (كان يُتتبَّع في `AuditLog`)
> تتبّع «مرة واحدة لكل (منشأة × مرحلة × فترة)» لدورة Dunning — بدل تخزينه في `AuditLog`. يمنع تكرار التذكير/التعليق تحت التزامن ويعطي سجلاً مُهيكَلاً.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK |
| `organization_id` | bigint | لا | — | FK → `organizations.id` · onDelete **CASCADE** |
| `subscription_id` | bigint | نعم | null | FK → `platform_subscriptions.id` · onDelete **SET NULL** |
| `stage` | varchar(24) | لا | — | enum مرحلة الدورة |
| `period_key` | varchar(40) | لا | — | مفتاح الفترة (مثل `YYYY-MM` أو نهاية الفترة) — يضمن مرّة لكل فترة |
| `channel` | varchar(12) | نعم | null | enum قناة الإرسال |
| `sent_at` | timestamptz | لا | now | لحظة الإطلاق |
| `meta` | jsonb | نعم | null | تفاصيل إضافية (رقم الفاتورة المُنشأة، أيام التأخّر…) |
| `created_at` | timestamptz | لا | now | |

- **enum `stage`** (CHECK): `PRE_RENEWAL_REMINDER` · `TRIAL_ENDING` · `RENEWAL_INVOICE` · `PAST_DUE_TRANSITION` · `OVERDUE_REMINDER` · `SUSPENDED`.
- **enum `channel`** (CHECK, nullable): `WHATSAPP` · `EMAIL` · `BOTH`.
- **فهارس/قيود:** **UNIQUE(`organization_id`, `stage`, `period_key`)** — القيد الجوهري الذي يفرض «مرّة واحدة» ذرّياً (يستبدل فحص `AuditLog.after.key`)؛ INDEX(`organization_id`, `sent_at`) لعرض آخر نشاط في الكونسول.
- **علاقات:** belongsTo `organizations`, belongsTo `platform_subscriptions`.
  > **لماذا جدول مستقل؟** الأصل خزّن المفتاح في `AuditLog` (بلا قيد فرادة، بحث نصّي في `after.key`). جدول مخصّص يمنح UNIQUE فعلياً على (منشأة، مرحلة، فترة) — أمتن ضد سباق النقر المزدوج/تشغيلين متزامنين للدورة — ويحرّر `AuditLog` للتدقيق فقط.

---

## تحويلات من Setting-JSON

الجداول التالية تُرقّي بيانات كانت مخزّنة كـ JSON غير مُهيكَل (في `PlatformConfig` أو `AuditLog`) إلى بُنى علائقية مُصنَّفة:

| المصدر القديم (JSON) | الوجهة الجديدة (أعمدة) | السبب |
|---|---|---|
| `PlatformConfig('platform')` — `trialDays`, `allowPublicSignup`, `seller*` | أعمدة `platform_settings` | فلاغات قياسية تُفحَص كل طلب (بوابة التسجيل، لقطة بائع الفاتورة) |
| `PlatformConfig('platform.dunning')` — enabled/remindDays*/graceDays/channels | أعمدة `platform_settings.dunning_*` | سياسة تُقرأ في كل دورة يومية؛ الأرقام القابلة للفلترة كأعمدة |
| `PlatformConfig('platformBooks')` — `{orgId}` | `platform_settings.platform_books_organization_id` (FK) | مرجع منشأة صريح بـ FF بدل JSON نصّي |
| تتبّع Dunning في `AuditLog.after.key` | جدول `dunning_logs` + UNIQUE(org, stage, period_key) | فرادة ذرّية حقيقية بدل بحث نصّي بلا قيد |
| `PlatformConfig('platform.customRoles')`, `platform.settings` (بُنى UI عميقة) | تبقى JSON (في `platform_configs` / `platform_settings.settings`) | إعدادات UI متداخلة تُحرَّر ككتلة، لا صفوف مُستعلَمة — JSON مناسب هنا |

> **ملاحظة:** تكاليف الموظفين (`hr.cost:{userId}`) وتجاوزات صلاحيات المستخدم (`user.permissions:{userId}`) و CRM notes للمنشأة **خارج نطاق هذا الملف** (كيانات المنشأة الداخلية / ملف 01) — تُترَك كما هي أو تُرقّى في ملفّها. تحكّمات المنصة على المنشأة (`isSuspended`, `featureOverrides`, `maxBranchesOverride`, `maxUsersOverride`, `adminFollowUp`, `adminTags`, `accountCredit`, `payoutConfig`) هي أعمدة على `organizations` نفسها → تُصمَّم في ملف `01-auth-roles-tenancy` (أعمدة nullable إضافية)، ويشير إليها هذا المجال فقط.

---

## دفاتر المنصة

> **المبدأ:** المنصة لا تملك جداول محاسبة منفصلة. تمسك دفاترها المزدوجة على **منشأة نظام محجوزة** (id مُشار إليه في `platform_settings.platform_books_organization_id`، اسمها «دفاتر المنصّة»). هذا يعيد استخدام **نفس جداول المحاسبة** لملف 08 (`accounts` / `journal_entries` / `journal_lines`) لكن مُقيَّدة على تلك المنشأة — فيحصل كونسول الأدمن على نفس ورش المحاسبة والتقارير كأي منشأة، وتُستثنى هذه المنشأة من كل قائمة/عدّ مواجه للمستأجرين (`tenants_only` / `is_platform_org`).

**لا جداول جديدة في هذا المجال للمحاسبة** — فقط قيود تُرحَّل عبر خدمة الترحيل الموحّدة على منشأة الدفاتر، بمخطّط حسابات خاص idempotent:

| القيد | المدين / الدائن | `source` · `ref_type` (الربط بالمصدر) |
|---|---|---|
| إيراد اشتراك (`postRevenue`) | Dr BANK إجمالي / Cr SALES صافي / Cr VAT_PAYABLE | `PAYMENT` · `SubscriptionInvoice` (ref_id = `subscription_invoices.id`) |
| بيع جهاز (`postDeviceSale`) | Dr BANK / Cr DEVICE_SALES صافي / Cr VAT_PAYABLE | `PAYMENT` · `DeviceSale` (ref_id = `device_sales.id`) |
| توزيع شريك (`postPartnerDistribution`) | Dr PARTNER_DRAWINGS / Cr BANK | `MANUAL` · `PlatformPartnerDistribution` |
| مصروف منصة (`postExpense`) | Dr OPEX / Cr CASH | `EXPENSE` · `PlatformExpense` |

- **قيد enum المصدر (حرِج):** لا توجد قيمة `SUBSCRIPTION` في enum `JournalSource` (وهو enum Postgres لا يُوسَّع). لذلك دفع الاشتراك/الجهاز يُميَّز بـ `source='PAYMENT'` + `ref_type` مناسب، **لا** بقيمة enum جديدة. (راجع قيود المشروع في `CLAUDE.md`.)
- **الترحيل idempotent** على `(organization_id, source, ref_type, ref_id)` — إعادة الاعتماد لا تُكرّر القيد.
- **حسابات خاصّة بمنشأة الدفاتر** (idempotent seeding): SALES مُعاد تسميته «إيرادات الاشتراكات» + حساب `DEVICE_SALES` (كود 4120) + حساب `PARTNER_DRAWINGS` (كود 3030, contra-equity).
- الاعتماد (`confirm`) يجمع قلب حالة الفاتورة إلى `ISSUED` + تخصيص سلوت ICV/PIH + الترحيل المحاسبي في **transaction واحدة** — فلا فاتورة معتمَدة بلا قيد مطابق ولا عكس.
