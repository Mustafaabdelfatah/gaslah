# مخطّط الجداول — السوق و ZATCA و الإعدادات و الأسرار و التدقيق

> نطاق هذا الملف (البناء النظيف): جداول السوق B2B، فواتير ZATCA وحالة تسجيل ZATCA لكل منشأة، سجل التدقيق، جدول الإعدادات الحرّة المتبقّي (key/value)، وجدول أسرار التكاملات المشفّرة. مشتقّ حرفياً من `docs/brd/11-zatca.md` و `docs/brd/13-market-settings-audit.md`.

## قواعد عامة سارية على كل الجداول هنا

- كل الجداول `snake_case` جمعاً، وكل الأعمدة `snake_case`.
- المفتاح الأساسي دائماً `id` من نوع `bigint` (auto-increment / `bigserial`).
- كل جدول له `legacy_cuid` نصي `nullable unique` — يحمل المعرّف النصي (cuid) القديم من قاعدة Prisma عند الاستيراد، ويربط الصفوف المرحّلة بمراجعها القديمة (مثل `pih` الذي كان يشير إلى `hash` بمعرّف نصي). بعد اكتمال الترحيل يصبح تاريخياً فقط.
- المفاتيح الأجنبية بصيغة `{singular}_id` مع FK فعلي و `onDelete` مصرّح.
- `organization_id` / `branch_id` تُضاف حسب نطاق الجدول (bigint FK على `organizations`/`branches` الجديدين في البناء النظيف).
- طوابع `created_at` / `updated_at` (`timestamptz`) على كل جدول إلا ما نُصّ خلافه (بعض جداول Prisma الأصلية بلا `updatedAt` — نُبقيها للأمانة لكن نضيف `updated_at` في البناء النظيف حيث يكون مفيداً، ونذكر ذلك صراحةً لكل جدول).
- المال: `decimal(14,2)`. النِسَب المئوية: `decimal(5,2)`.
- الحالات/الأنواع: عمود `string` + PHP enum مطابق + قيد `CHECK` بقائمة القيم.
- الأسرار: أعمدة **مشفّرة at rest** (AES-256-GCM عبر `SecretValue`, بادئة `enc:v1:`) — لا تُرجَع للواجهة أبداً؛ يُعاد بدلها علَم منطقي `*_set`.
- الحقول غير المنظّمة فعلاً (snapshots/metadata) → `jsonb`.

---

## 1) السوق B2B

### `market_suppliers`  ← كان: `MarketSupplier`
> مورّد أعمال في سوق المستلزمات، معتمد من المنصة، له بوابة دخول مستقلّة (بريد + كلمة مرور). مملوك للمنصّة لا للمستأجر (لا `organization_id`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | cuid القديم، unique |
| `name` | string | لا | — | الاسم التجاري |
| `email` | string(191) | لا | — | مفتاح الدخول؛ يُخزَّن lowercase؛ **unique** |
| `phone` | string | نعم | — | الهاتف |
| `password_hash` | string | لا | — | هاش bcrypt (`$2b$…`)؛ **مخفي دائماً** (`$hidden`)، لا يُسلسَل، يُطابَق بـ `password_verify()` |
| `status` | string | لا | `'PENDING'` | enum المورّد؛ CHECK `IN (PENDING,APPROVED,REJECTED,SUSPENDED)` |
| `description` | text | نعم | — | وصف المورّد |
| `city` | string | نعم | — | المدينة |
| `logo_url` | string | نعم | — | شعار المورّد |
| `commission_type` | string | لا | `'PERCENT'` | نوع العمولة؛ CHECK `IN (PERCENT,FIXED,SUBSCRIPTION)` |
| `commission_value` | decimal(14,2) | نعم | — | نسبة % (إن PERCENT) أو مبلغ ثابت (إن FIXED)؛ فارغ ⇒ افتراضات الحساب |
| `approved_at` | timestamptz | نعم | — | لحظة الاعتماد |
| `created_at` | timestamptz | لا | now | — |
| `updated_at` | timestamptz | لا | now | (كان بلا `updatedAt` في Prisma؛ يُضاف في البناء النظيف) |

- **فهارس/قيود:** `UNIQUE(email)`؛ `UNIQUE(legacy_cuid)`؛ فهرس `(status)` (تصفية الظهور للمشترين على `APPROVED`)؛ فهرس `(city)`؛ CHECK على `status` و `commission_type`؛ CHECK `commission_value >= 0`.
- **PHP enums:** `MarketSupplierStatus{Pending,Approved,Rejected,Suspended}`, `CommissionType{Percent,Fixed,Subscription}`.
- **علاقات:** `hasMany market_products`، `hasMany market_orders`.

---

### `market_products`  ← كان: `MarketProduct`
> منتج مدرَج من مورّد (مستلزمات/مواد مغاسل). يظهر للمشتري فقط إذا `is_active=true` ومورّده `APPROVED`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | unique |
| `supplier_id` | bigint | لا | — | FK → `market_suppliers(id)` `ON DELETE CASCADE` |
| `name` | string | لا | — | الاسم العربي |
| `name_en` | string | نعم | — | الاسم الإنجليزي |
| `category` | string(60) | لا | — | فئة نصّية حرّة (القائمة الثابتة للعرض فقط؛ انظر §7) |
| `description` | text | نعم | — | الوصف |
| `unit` | string | لا | `'قطعة'` | وحدة القياس |
| `price` | decimal(14,2) | لا | — | سعر الوحدة |
| `stock` | integer | نعم | — | المخزون (null = غير محدود) |
| `image_url` | string | نعم | — | نمط `^(https?://|/)` |
| `is_active` | boolean | لا | `true` | التفعيل/الإيقاف عبر التحديث |
| `created_at` | timestamptz | لا | now | — |
| `updated_at` | timestamptz | لا | now | (كان بلا `updatedAt` في Prisma) |

- **فهارس/قيود:** `UNIQUE(legacy_cuid)`؛ فهرس `(supplier_id)`؛ فهرس `(category)`؛ فهرس `(is_active)`؛ فهرس مركّب `(supplier_id, is_active)` لاستعلامات بوابة المورّد؛ (اختياري) فهرس GIN/trigram على `name` لبحث `ILIKE`؛ CHECK `price >= 0`، `stock IS NULL OR stock >= 0`.
- **علاقات:** `belongsTo market_suppliers`؛ `hasMany market_order_items` عبر `product_id`.

---

### `market_orders`  ← كان: `MarketOrder`
> طلب شراء لمستأجر مغسلة من مورّد واحد. `total = subtotal`؛ العمولة تُخصَم من جهة المورّد فقط (`supplier_payout = subtotal − commission_amount`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `ON DELETE RESTRICT` — المنشأة المشترية |
| `branch_id` | bigint | نعم | — | FK → `branches(id)` `ON DELETE SET NULL` — فرع الكاتب |
| `supplier_id` | bigint | لا | — | FK → `market_suppliers(id)` `ON DELETE RESTRICT` — مورّد واحد لكل طلب |
| `status` | string | لا | `'PENDING'` | enum؛ CHECK `IN (PENDING,CONFIRMED,SHIPPED,DELIVERED,CANCELLED)` |
| `payment_method` | string | لا | `'DEFERRED'` | CHECK `IN (ONLINE,DEFERRED)` |
| `payment_status` | string | لا | `'UNPAID'` | CHECK `IN (UNPAID,PAID)` — يبدأ UNPAID (لا مسار تحويل حالياً) |
| `subtotal` | decimal(14,2) | لا | — | مجموع الأسطر قبل الاقتطاع |
| `commission_type` | string | لا | — | لقطة من المورّد؛ CHECK `IN (PERCENT,FIXED,SUBSCRIPTION)` |
| `commission_rate` | decimal(5,2) | لا | `0` | نسبة % (0 لغير PERCENT) |
| `commission_amount` | decimal(14,2) | لا | `0` | مبلغ العمولة للمنصّة |
| `total` | decimal(14,2) | لا | — | = `subtotal` |
| `supplier_payout` | decimal(14,2) | لا | — | = `subtotal − commission_amount` |
| `address` | string | نعم | — | عنوان اختياري |
| `notes` | string(500) | نعم | — | ملاحظات (≤500) |
| `created_by_id` | bigint | نعم | — | FK → `users(id)` `ON DELETE SET NULL` — الكاتب (من claims) |
| `inventory_applied` | boolean | لا | `false` | علَم — غير مكتوب في المسار الحالي |
| `accounting_posted` | boolean | لا | `false` | علَم — غير مكتوب في المسار الحالي |
| `delivered_at` | timestamptz | نعم | — | يُختَم عند الانتقال إلى `DELIVERED` |
| `created_at` | timestamptz | لا | now | — |
| `updated_at` | timestamptz | لا | now | (كان بلا `updatedAt` في Prisma؛ لكن الحالة تتغيّر ⇒ مفيد) |

- **فهارس/قيود:** `UNIQUE(legacy_cuid)`؛ فهرس `(organization_id, created_at DESC)`؛ فهرس `(supplier_id, created_at DESC)`؛ فهرس `(status)`؛ CHECK على الحقول enum؛ CHECK `subtotal >= 0`, `commission_amount >= 0`, `commission_amount <= subtotal`, `supplier_payout >= 0`, `commission_rate BETWEEN 0 AND 100`.
- **آلة الحالات (`SUPPLIER_FLOW`):** `PENDING→CONFIRMED|CANCELLED`, `CONFIRMED→SHIPPED|CANCELLED`, `SHIPPED→DELIVERED`, `DELIVERED/CANCELLED` نهائية — يُطبَّق في طبقة الخدمة (422 لأي انتقال آخر).
- **PHP enums:** `MarketOrderStatus`, `MarketPaymentMethod{Online,Deferred}`, `MarketPaymentStatus{Unpaid,Paid}`.
- **علاقات:** `belongsTo organizations, branches, market_suppliers, users(created_by)`؛ `hasMany market_order_items`.

---

### `market_order_items`  ← كان: `MarketOrderItem`
> سطر واحد على طلب سوق، بلقطة snapshot للاسم/الوحدة/السعر وقت الطلب. (في Prisma بلا `createdAt`/`updatedAt`.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | unique |
| `order_id` | bigint | لا | — | FK → `market_orders(id)` `ON DELETE CASCADE` |
| `product_id` | bigint | نعم | — | FK → `market_products(id)` `ON DELETE SET NULL` (لقطة تبقى بعد حذف المنتج) |
| `name` | string | لا | — | لقطة اسم المنتج |
| `unit` | string | لا | — | لقطة الوحدة |
| `unit_price` | decimal(14,2) | لا | — | لقطة سعر الوحدة |
| `quantity` | decimal(14,2) | لا | — | الكمية (>0, ≤100000) |
| `line_total` | decimal(14,2) | لا | — | `unit_price × quantity` (مقرَّب 2) |

- **فهارس/قيود:** `UNIQUE(legacy_cuid)`؛ فهرس `(order_id)`؛ فهرس `(product_id)`؛ CHECK `quantity > 0`, `unit_price >= 0`, `line_total >= 0`.
- **ملاحظة الطوابع:** في البناء النظيف يمكن إضافة `created_at` فقط (لا تحديث للأسطر)؛ نُبقيها اختيارية وفاءً للأصل.
- **علاقات:** `belongsTo market_orders, market_products`.

---

## 2) ZATCA

### `zatca_registrations`  ← كان: محوّل من `Setting` key `zatca.state:{orgId}` (JSON) + ملفات `storage/app/zatca/{orgId}/`
> حالة تسجيل ZATCA لكل منشأة: سلسلة CSID (compliance ثم production)، requestID، الطوابع، ومسارات/بصمات المفاتيح والشهادات. **كل الأسرار مشفّرة at rest ولا تُرجَع للواجهة.** صف واحد لكل منشأة.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `ON DELETE CASCADE`؛ **unique** (صف واحد/منشأة) |
| `environment` | string | لا | `'sandbox'` | CHECK `IN (sandbox,simulation,production)` |
| `vat_number` | string | نعم | — | لقطة الرقم الضريبي المستخدم للتسجيل |
| `csr_pem` | text | نعم | — | الـ CSR المُولَّد (secp256k1) — غير سرّي |
| `private_key_path` | string | نعم | — | مسار المفتاح الخاص على القرص (`storage/app/zatca/{orgId}/ec-private-key.pem`, 0600) |
| `csid_cert_pem` | text | نعم | — | شهادة CSID الفعّالة (production مفضّلة، وإلا compliance) — عامة |
| `cert_fingerprint` | string(64) | نعم | — | بصمة SHA-256 للشهادة الموقِّعة (للوحة الحالة) |
| `compliance_binary_token` | text (encrypted) | نعم | — | `complianceCsid.binarySecurityToken` — مشفّر، لا يُرجَع |
| `compliance_secret` | text (encrypted) | نعم | — | `complianceCsid.secret` (Basic-auth) — مشفّر، لا يُرجَع |
| `compliance_request_id` | string | نعم | — | `complianceCsid.requestID` (غير حسّاس، يُرجَع) |
| `production_binary_token` | text (encrypted) | نعم | — | `productionCsid.binarySecurityToken` — مشفّر، لا يُرجَع |
| `production_secret` | text (encrypted) | نعم | — | `productionCsid.secret` — مشفّر، لا يُرجَع |
| `production_request_id` | string | نعم | — | `productionCsid.requestID` |
| `complied_at` | timestamptz | نعم | — | لحظة نجاح compliance CSID |
| `onboarded_at` | timestamptz | نعم | — | لحظة نجاح production CSID |
| `created_at` | timestamptz | لا | now | — |
| `updated_at` | timestamptz | لا | now | — |

- **فهارس/قيود:** `UNIQUE(organization_id)`؛ CHECK على `environment`.
- **حالة مشتقّة (لا تُخزَّن):** `has_csr = csr_pem IS NOT NULL`؛ `onboarded = compliance_binary_token IS NOT NULL`؛ `has_production_csid = production_binary_token IS NOT NULL`؛ `active_csid = production ?? compliance`.
- **أمن الأسرار:** الأعمدة `*_binary_token` و `*_secret` **مشفّرة عبر `SecretValue::encrypt`** وتُفكّ عند القراءة الداخلية فقط؛ لا تظهر في أي رد API — يُرجَع بدلها `requestID` والطوابع والأعلام المنطقية.
- **علاقات:** `belongsTo organizations`.

---

### `zatca_invoices`  ← كان: `ZatcaInvoice`
> فاتورة UBL 2.1 مخزّنة (المرحلة 2)، صف واحد لكل طلب، ضمن سلسلة ICV/PIH/UUID غير قابلة للتلاعب لكل منشأة. **idempotent على `order_id`.**

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | unique |
| `order_id` | bigint | لا | — | FK → `orders(id)` `ON DELETE RESTRICT`؛ **unique** (فاتورة واحدة/طلب) |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `ON DELETE RESTRICT` — عزل المستأجر |
| `icv` | integer | لا | — | عدّاد الفاتورة المتسلسل لكل منشأة، يبدأ من 1؛ **unique مركّب `(organization_id, icv)`** |
| `uuid` | uuid | لا | — | UUID الفاتورة (يُدرَج في XML ويُرسَل للهيئة) |
| `pih` | text | لا | — | Previous Invoice Hash (base64)؛ للأولى = `GENESIS_PIH` |
| `hash` | text | لا | — | SHA-256(XML) base64 — يغذّي الوسم 6 ويصبح PIH للتالية |
| `xml` | text | لا | — | فاتورة UBL 2.1 المُسلسَلة كاملةً |
| `qr` | text | لا | — | QR بترميز TLV (الوسوم 1..6؛ base64) |
| `status` | string | لا | `'GENERATED'` | enum؛ CHECK `IN (GENERATED,REPORTED)` |
| `zatca_uuid` | string | نعم | — | المعرّف المُعاد من الهيئة بعد الإبلاغ (`body.uuid`) |
| `reported_at` | timestamptz | نعم | — | لحظة الإبلاغ الناجح |
| `created_at` | timestamptz | لا | now | — |

- **فهارس/قيود:**
  - `UNIQUE(order_id)` — يفرض «فاتورة واحدة لكل طلب» ويغذّي فحص idempotent.
  - `UNIQUE(organization_id, icv)` — يفرض تسلسل ICV لكل منشأة (تصادم رمز postgres `23505` يُدير حلقة إعادة حساب ICV).
  - `UNIQUE(legacy_cuid)`؛ فهرس `(organization_id, icv DESC)` لجلب آخر ICV/PIH بسرعة؛ فهرس `(status)`.
  - CHECK `icv >= 1`؛ CHECK على `status`.
- **ملاحظة الطوابع:** جدول Prisma الأصلي بلا `updatedAt` (`const UPDATED_AT = null`)؛ نُبقي `created_at` فقط في البناء النظيف — الفاتورة لا تُحدَّث إلا لختم الإبلاغ، ويمكن الاكتفاء بـ `reported_at`.
- **آلة الحالات:** `GENERATED → REPORTED` (مع `zatca_uuid` + `reported_at`)؛ لا انتقال عكسي؛ فشل الإبلاغ يُبقي `GENERATED`.
- **PHP enum:** `ZatcaInvoiceStatus{Generated,Reported}`.
- **علاقات:** `belongsTo orders, organizations`.

> **الأعمدة 1..6 من QR** مخزّنة في `qr`؛ الوسمان 7/8 (التوقيع/المفتاح العام) يُلحقان لحظياً عند الإبلاغ من `zatca_registrations` (الشهادة/المفتاح) ولا يُخزَّنان.

---

## 3) التدقيق

### `audit_logs`  ← كان: `AuditLog`
> سجل **غير قابل للتعديل** (append-only) لِـ«من غيّر ماذا ومتى» مع لقطات before/after. الكتابة داخلية عبر كاتب موثوق (`AuditTrail::log` بـ `forceCreate`) فقط؛ لا نقطة API للإنشاء/التعديل/الحذف.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | unique |
| `organization_id` | bigint | نعم | — | FK → `organizations(id)` `ON DELETE SET NULL` — **يُضاف في البناء النظيف** (الأصل بلا orgId، والعزل كان استدلالاً عبر `UserBranch`) |
| `user_id` | bigint | نعم | — | FK → `users(id)` `ON DELETE SET NULL` — الفاعل (قد يكون null) |
| `action` | string | لا | — | نوع الإجراء: `CREATE/UPDATE/DELETE/PAY/VOID/REORDER/…` (نصّي حرّ، لا CHECK صارم لأن المستدعي يحدّده) |
| `entity` | string | لا | — | اسم الكيان (`Payable/Budget/Asset/…`) |
| `entity_id` | string | نعم | — | معرّف السجل المتأثّر (نصّي — قد يشير لكيانات متعددة الأنواع) |
| `before` | jsonb | نعم | — | لقطة ما قبل (null للإنشاء) |
| `after` | jsonb | نعم | — | لقطة ما بعد (null للحذف) |
| `ip` | string(45) | نعم | — | IP الفاعل (يتّسع لـ IPv6) |
| `created_at` | timestamptz | لا | now | مختوم يدوياً؛ **لا `updated_at`** (append-only) |

- **فهارس/قيود:** `UNIQUE(legacy_cuid)`؛ فهرس `(organization_id, created_at DESC)`؛ فهرس `(entity)`؛ فهرس `(action)`؛ فهرس `(user_id)`؛ (للـ facets: `entities`/`actions` المميّزة تُستخرَج بـ DISTINCT مقيّد بـ `organization_id`).
- **الحماية:** محمي من mass-assignment (`$guarded`/`forceCreate`)؛ لا `UPDATE`/`DELETE` من التطبيق (يُفضَّل منعها على مستوى الصلاحيات/التريغر).
- **ملاحظة الترحيل:** إضافة `organization_id` تُلغي قيد العزل البنيوي عبر `UserBranch` وتُبسّط الاستعلام إلى `WHERE organization_id = ?`.
- **علاقات:** `belongsTo organizations, users`.

---

## 4) الإعدادات الحرّة المتبقّية

### `settings`  ← كان: `Setting` (المتبقّي فعلاً كـ key/value حرّ بعد ترحيل المهيكَل)
> جدول key/value عامّ لكل مستأجر، للبيانات التي **تبقى فعلاً غير مهيكَلة** بعد نقل المجموعات المعروفة إلى أعمدة/جداول (انظر §6). يحفظ نمط `OrgStore` (`{type}:{orgId}:{id}`) للكيانات الخفيفة المتبقّية، وأي مفتاح تكوين لم يُرحَّل بعد.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `legacy_cuid` | string | نعم | — | unique (كان `id` نصّي cuid في Prisma) |
| `organization_id` | bigint | نعم | — | FK → `organizations(id)` `ON DELETE CASCADE` (nullable لمفاتيح على مستوى المنصّة) |
| `branch_id` | bigint | نعم | — | FK → `branches(id)` `ON DELETE CASCADE` |
| `key` | string(191) | لا | — | مفتاح الإعداد (مثل `{type}:{orgId}:{id}` القديم أو `namespace.name`) |
| `value` | jsonb | نعم | — | القيمة (JSON بدل نص خام لاستعلام أفضل) |
| `created_at` | timestamptz | لا | now | — |
| `updated_at` | timestamptz | لا | now | يُختَم يدوياً (كان يدوياً في Prisma) |

- **فهارس/قيود:** `UNIQUE(organization_id, key)` (وحيد لكل منشأة)؛ فهرس `(organization_id)`؛ فهرس `(key)`؛ فهرس GIN على `value` عند الحاجة لاستعلامات داخل JSON. الفهرسة على `(organization_id, key)` تُلغي مشكلة `LIKE 'prefix%'` القديمة التي لا تستفيد من الفهارس.
- **ملاحظة:** في Prisma كان `Setting` بلا cuid boot وبلا timestamps تلقائية؛ هنا نُبقي التحكّم اليدوي في `updated_at` للتوافق السلوكي إن لزم، لكن الجدول جدول حقيقي مفهرَس.
- **علاقات:** `belongsTo organizations, branches`.

---

## 5) أسرار التكاملات

### `integration_secrets`  ← كان: محوّل من `Setting` keys `payment.config:{orgId}` و `messaging.config:{orgId}` (أجزاؤها السرّية)
> جدول مخصّص لأسرار التكاملات لكل منشأة (بوابة الدفع، واتساب، SMS) بتشفير على مستوى العمود. **الأسرار لا تُرسَل للمتصفح أبداً**؛ GET يعيد أعلام `*_set` منطقية فقط، و PUT بفراغ يُبقي القديم (لا يمحو).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|------|------|------|--------|-------------|
| `id` | bigint | لا | auto | PK |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `ON DELETE CASCADE`؛ **unique** (صف واحد/منشأة) |
| `payment_provider` | string | لا | `'stub'` | CHECK `IN (stub,moyasar,hyperpay)` — بوابة الدفع |
| `payment_public_key` | string(500) | نعم | — | مفتاح عام (غير سرّي، لكن يبقى هنا مع التكوين) |
| `payment_secret_key` | text (encrypted) | نعم | — | `gateway.secretKey` — مشفّر، لا يُرجَع؛ علَم `pay_secret` |
| `whatsapp_token` | text (encrypted) | نعم | — | `whatsapp.token` — مشفّر، لا يُرجَع؛ علَم `wa_token` |
| `sms_api_key` | text (encrypted) | نعم | — | `sms.apiKey` — مشفّر، لا يُرجَع؛ علَم `sms_api_key` |
| `created_at` | timestamptz | لا | now | — |
| `updated_at` | timestamptz | لا | now | — |

- **فهارس/قيود:** `UNIQUE(organization_id)`؛ CHECK على `payment_provider`.
- **أمن الأسرار:** الأعمدة `*_secret_key`/`*_token`/`*_api_key` **مشفّرة عبر `SecretValue` (AES-256-GCM, `enc:v1:`)** عند الكتابة، وتُفكّ داخلياً فقط. القراءة عبر API تعيد الأسرار **فارغة** مع `secretsSet{ paySecret, waToken, smsApiKey }`. الكتابة بفراغ (بعد trim) تُبقي القيمة المخزّنة (دالة `keep`). غياب مفتاح التشفير `SETTINGS_ENCRYPTION_KEY` ⇒ استثناء.
- **ملاحظة النطاق:** الأجزاء **غير السرّية** من التكاملات (أعلام `methods`، `gateway.provider`, `whatsapp.enabled/mode/phoneId`, `sms.enabled/provider/sender`, `events`, `templates`) تبقى تكويناً مهيكَلاً (أعمدة على المنشأة أو صف `settings`/جدول تكوين) — هذا الجدول يحمل **الأسرار فقط** إضافةً إلى `payment_provider`/`payment_public_key` الملازمَين لسرّ الدفع. (نطاق التكوين المهيكَل الكامل خارج هذا الملف.)
- **علاقات:** `belongsTo organizations`.

---

## 6) تحويلات من Setting-JSON

جدول Prisma `Setting` كان يخزّن عدّة عائلات JSON بمفتاح نصّي. في البناء النظيف تتوزّع كالتالي:

| مفتاح Setting القديم | الوجهة في البناء النظيف | ملاحظة |
|------|------|------|
| `zatca.state:{orgId}` | **`zatca_registrations`** (§2) | CSID/PIH-chain state؛ الأسرار (`compliance/production .secret` و `.binarySecurityToken`) → أعمدة مشفّرة؛ requestID/الطوابع → أعمدة عادية. المفاتيح/الشهادات تبقى ملفات على القرص، ومساراتها/بصمتها في الجدول. |
| `payment.config:{orgId}` (الجزء السرّي) | **`integration_secrets`** (§5) | `gateway.secretKey` → `payment_secret_key` مشفّر؛ `provider`/`publicKey` → أعمدة عادية. الأعلام `methods` (تكوين غير سرّي) → تكوين مهيكَل خارج هذا الملف. |
| `messaging.config:{orgId}` (الجزء السرّي) | **`integration_secrets`** (§5) | `whatsapp.token` → `whatsapp_token` مشفّر؛ `sms.apiKey` → `sms_api_key` مشفّر. باقي المراسلة (enabled/mode/phoneId/events/templates/sender) تكوين غير سرّي → تكوين مهيكَل خارج هذا الملف. |
| `automation.config:{orgId}` | تكوين مهيكَل (خارج نطاق هذا الملف) | أعمدة/جدول إعدادات على المنشأة. إن بقي حرّاً مؤقتاً ⇒ `settings` (§4). |
| `credit.config:{orgId}` | تكوين مهيكَل (خارج النطاق) | كذلك. |
| `notifications.config:{orgId}` | تكوين مهيكَل (خارج النطاق) | كذلك. |
| `portal.config:{orgId}` (offers: `showOffers/termsUrl/privacyUrl`) | أعمدة على المنشأة (خارج النطاق) | يحلّ ازدواج المفتاح بين `PUT /settings/portal` ومجموعة `offers` بجعله أعمدة صريحة. |
| `OrgStore` عام `{type}:{orgId}:{id}` | جداول كيانات فعلية مفهرَسة بـ `organization_id`، أو **`settings`** (§4) للخفيف المتبقّي فعلاً | يُلغى `LIKE 'prefix%'` لصالح فهرس `(organization_id, key)`. |

> **ملاحظة الأسرار العامة:** كل عمود مذكور أنه «مشفّر» يُخزَّن بغلاف `SecretValue` (AES-256-GCM، بادئة `enc:v1:`، AAD `laundry-settings-v1`)، لا يُسلسَل في أي رد API، ولا يُسجَّل في logs. الوصول للفكّ داخلي فقط (طبقة الخدمة) عند الحاجة للاستدعاء الخارجي (بوابة الدفع/ZATCA/واتساب/SMS).
