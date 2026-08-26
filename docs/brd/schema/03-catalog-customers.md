# مخطط الجداول — الكتالوج والعملاء (03)

> اشتقاق نظيف من `docs/brd/02-catalog-customers.md` إلى مخطط علائقي حديث (بدل نماذج Prisma بمعرّفات cuid).
> **المرجع الأصلي:** أسماء نماذج Prisma الحالية مذكورة بعد سهم `←` في كل جدول للربط أثناء الهجرة (Strangler).

---

## 0. الاصطلاحات المطبَّقة في هذا الملف

- كل الجداول **snake_case جمع**، وكل الأعمدة **snake_case**.
- **المفتاح الأساسي** `id` من نوع `bigint unsigned auto-increment` في كل جدول.
- **`legacy_cuid`**: عمود `string(191) nullable unique` في كل جدول يُستورَد من نظام Prisma الحالي؛ يحمل الـ cuid القديم لتعقّب الهجرة وربط المفاتيح الأجنبية أثناء النقل، ثم يبقى للتدقيق. (الجداول المرجعية البحتة التي لا تُستورَد قد لا تحتاجه — مُشار إليه.)
- **المفاتيح الأجنبية** `{singular}_id` (bigint unsigned) تشير إلى `id` في الجدول المرجعي، مع `onDelete` صريح.
- **`organization_id`**: للكيانات المملوكة لمنشأة (عزل المستأجر). **`branch_id`**: لكيان مرتبط بفرع.
- **الطوابع الزمنية:** `created_at` / `updated_at` (nullable) في كل جدول ما لم يُذكر خلاف ذلك. **`deleted_at`** للحذف الناعم حيث يلزم.
- **الأموال:** `decimal(14,2)`. **النِّسَب:** `decimal(5,2)`. **معاملات التحويل:** `decimal(12,4)`.
- **الحالات/الأنواع:** عمود `string` + PHP enum (backed) + قيد `CHECK` بقائمة القيم المسموحة الصريحة (بدل Postgres enum غير القابل للتوسيع الآمن).
- **JSON** يُستخدم لـ metadata/تفضيلات حرة فقط؛ أي إعداد منظّم كان في `Setting`-JSON صار أعمدة (انظر القسم الأخير).

---

## 1. كيانات الكتالوج

### `service_categories`  ← كان: `ServiceCategory`
> تصنيف المستوى الأعلى في الكتالوج (مثال: "ملابس رجالية"). يجمع منتجات المنشأة ويُرتَّب في POS.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string(191) | نعم | — | unique، معرّف Prisma القديم |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (onDelete: CASCADE) — المنشأة المالكة |
| `name` | string(191) | لا | — | الاسم العربي المعروض |
| `name_en` | string(191) | نعم | null | الاسم الإنجليزي (اختياري) |
| `icon` | string(191) | نعم | null | أيقونة/رمز العرض |
| `sort_order` | integer | لا | 0 | ترتيب الظهور تصاعدياً (الإنشاء = max+1) |
| `is_active` | boolean | لا | true | التعطيل يخفيه من POS بلا حذف |
| `created_at` | timestamp | نعم | — | |
| `updated_at` | timestamp | نعم | — | (النموذج الأصلي بلا timestamps؛ أُضيفت هنا للتدقيق) |

- **فهارس/قيود:** فهرس `(organization_id, is_active, sort_order)` لاستعلام عرض الكتالوج. فهرس على `organization_id`.
- **علاقات:** `hasMany products` (عبر `category_id`)، `hasMany services` (عبر `category_id`).

---

### `products`  ← كان: `Product`
> القطعة القابلة للتسعير (مثال: "ثوب") داخل تصنيف. لكل منتج عدة خلايا سعر (`services`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string(191) | نعم | — | unique |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (CASCADE) |
| `category_id` | bigint unsigned | لا | — | FK → `service_categories.id` (onDelete: RESTRICT) — التصنيف الأب |
| `name` | string(191) | لا | — | اسم القطعة |
| `name_en` | string(191) | نعم | null | الاسم الإنجليزي |
| `icon` | string(191) | نعم | null | أيقونة العرض |
| `code` | string(64) | نعم | null | رمز المنتج (باركود/كود داخلي) — يُحرَّر بصلاحية `catalog.manageCodes` |
| `sort_order` | integer | لا | 0 | الترتيب داخل التصنيف (الإنشاء = max+1) |
| `is_active` | boolean | لا | true | التعطيل يخفيه من POS بلا حذف |
| `created_at` | timestamp | نعم | — | |
| `updated_at` | timestamp | نعم | — | |

- **فهارس/قيود:**
  - **فريد مركّب `(organization_id, code)`** حيث `code IS NOT NULL` (partial unique index) — الرمز فريد داخل المنشأة فقط، ويُسمح بتعدد القيم null.
  - فهرس `(organization_id, category_id, is_active, sort_order)` لعرض/ترتيب المنتجات.
- **علاقات:** `belongsTo service_categories` (`category_id`)، `hasMany services` (`product_id`).

---

### `services`  ← كان: `Service`  (خلية السعر)
> خلية سعر واحدة = منتج × نوع خدمة واحد. `order_items.service_id` يشير إليها، لذلك **تُعطَّل ولا تُحذف**.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK (يُخزَّن في `order_items.service_id`) |
| `legacy_cuid` | string(191) | نعم | — | unique |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (CASCADE) |
| `category_id` | bigint unsigned | لا | — | FK → `service_categories.id` (RESTRICT) — منسوخ من المنتج |
| `product_id` | bigint unsigned | لا | — | FK → `products.id` (onDelete: RESTRICT) — المنتج الأب |
| `service_type` | string(16) | لا | — | enum: `WASH_IRON` / `IRON` / `WASH` (CHECK) |
| `name` | string(191) | لا | — | مطابق لاسم المنتج (يُزامَن عند إعادة التسمية) |
| `name_en` | string(191) | نعم | null | الاسم الإنجليزي |
| `pricing_type` | string(16) | لا | 'PER_PIECE' | enum: `PER_PIECE` / `PER_WEIGHT` (CHECK) — الإنشاء دائماً `PER_PIECE` |
| `base_price` | decimal(14,2) | لا | 0.00 | السعر الأساسي (≥0) |
| `express_surcharge` | decimal(14,2) | لا | 0.00 | رسوم الاستعجال المضافة (≥0) |
| `is_express_available` | boolean | لا | true | هل الاستعجال متاح |
| `is_active` | boolean | لا | true | التعطيل بدل الحذف (مرتبط بالطلبات) |
| `created_at` | timestamp | نعم | — | |
| `updated_at` | timestamp | نعم | — | |

- **السعر المحسوب (غير مخزَّن):** العادي = `base_price`؛ الاستعجال = `base_price + express_surcharge`.
- **فهارس/قيود:**
  - **فريد مركّب `(product_id, service_type)`** — خلية واحدة لكل نوع خدمة داخل المنتج (يمنع تكرار WASH لنفس المنتج).
  - `CHECK (service_type IN ('WASH_IRON','IRON','WASH'))`.
  - `CHECK (pricing_type IN ('PER_PIECE','PER_WEIGHT'))`.
  - `CHECK (base_price >= 0 AND express_surcharge >= 0)`.
  - فهرس `(organization_id, is_active)` لعرض الكتالوج.
- **قاعدة معمارية:** لا مسار حذف؛ `is_active=false` فقط — لأن `order_items.service_id` قد يشير للخلية. لذا FK نحو `products`/`categories` بـ **RESTRICT** لا CASCADE، صيانةً لسلامة السجل.
- **علاقات:** `belongsTo products` (`product_id`)، `belongsTo service_categories` (`category_id`)، (منطقياً) `hasMany order_items` (`service_id`).

---

### `garment_types`  ← كان: `GarmentType`
> نوع الملبس (ثوب/قميص/بنطال) لإثراء وصف بند الفاتورة. `order_items.garment_type_id` يشير إليه. **قراءة فقط في هذا الـ API** (لا CRUD — فجوة معروفة).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string(191) | نعم | — | unique |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (CASCADE) |
| `name` | string(191) | لا | — | اسم النوع (يُنسخ إلى بند الفاتورة/XML ZATCA) |
| `name_en` | string(191) | نعم | null | الاسم الإنجليزي |
| `sort_order` | integer | لا | 0 | ترتيب العرض |
| `is_active` | boolean | لا | true | |
| `created_at` | timestamp | نعم | — | |
| `updated_at` | timestamp | نعم | — | |

- **فهارس/قيود:** فهرس `(organization_id, is_active)`.
- **علاقات:** (منطقياً) `hasMany order_items` (`garment_type_id`). يُقرأ عبر `whereIn('id', …)` لتسمية البنود.

---

### `units`  ← كان: `Unit`
> وحدة قياس المخزون (قطعة/لتر/كيلو)، مقيّدة بمنشأة. تفاصيلها الكاملة في مجال المخزون؛ مُدرجة هنا لاكتمال أصل الطلب. **لا CRUD في مجال الكتالوج.**

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string(191) | نعم | — | unique |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (CASCADE) |
| `name` | string(191) | لا | — | اسم الوحدة (مثال: "لتر") |
| `symbol` | string(32) | نعم | null | الرمز (مثال: "L") |
| `conversion_factor` | decimal(12,4) | لا | 1.0000 | معامل التحويل للوحدة الأساسية |
| `created_at` | timestamp | نعم | — | |
| `updated_at` | timestamp | نعم | — | |

- **فهارس/قيود:** فهرس `organization_id`. (اختياري) فريد `(organization_id, name)`.
- **علاقات:** `hasMany inventory_items` (`unit_id`) — يُعرَّف في مجال المخزون.

---

## 2. كيانات العملاء

### `customers`  ← كان: `Customer`
> سجل العميل: تُنسب إليه الطلبات والمحافظ والاشتراكات. الهاتف فريد **داخل المنشأة فقط**.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string(191) | نعم | — | unique |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (CASCADE) — نطاق تفرّد الهاتف |
| `branch_id` | bigint unsigned | لا | — | FK → `branches.id` (onDelete: RESTRICT) — فرع الإنشاء (نطاق قراءة القوائم) |
| `name` | string(200) | لا | — | 2–200 حرف (يُنسخ إلى XML ZATCA وقوالب واتساب) |
| `phone` | string(32) | لا | — | 6–32 حرف — **فريد داخل المنشأة** |
| `email` | string(200) | نعم | null | يُطبَّع الفارغ إلى null |
| `address` | string(500) | نعم | null | عنوان نصّي حر مفرد (يختلف عن `customer_addresses`) |
| `birth_date` | date | نعم | null | تاريخ الميلاد (عروض أعياد الميلاد) |
| `type` | string(16) | لا | 'REGULAR' | enum: `REGULAR` / `VIP` / `CORPORATE` (CHECK) |
| `credit_limit` | decimal(14,2) | نعم | null | السقف الائتماني للآجل (≥0) — انظر قسم الائتمان |
| `wallet_balance` | decimal(14,2) | لا | 0.00 | **إشارة فقط**؛ كل حركاته عبر `WalletService` مع قفل `FOR UPDATE` |
| `preferences` | json | نعم | null | تفضيلات حرة (metadata) |
| `created_at` | timestamp | نعم | — | مُدارة (النموذج الأصلي مع timestamps) |
| `updated_at` | timestamp | نعم | — | يُستخدم لترتيب القوائم |
| `deleted_at` | timestamp | نعم | null | حذف ناعم (الحذف مرفوض إن كان له طلبات) |

- **فهارس/قيود:**
  - **فريد مركّب `(organization_id, phone)`** — تفرّد الهاتف داخل المنشأة (يجوز تكراره عبر منشآت مختلفة).
  - فهرس `(organization_id, branch_id, updated_at)` لقائمة العملاء (الأحدث تحديثاً أولاً).
  - `CHECK (type IN ('REGULAR','VIP','CORPORATE'))`.
  - `CHECK (credit_limit IS NULL OR credit_limit >= 0)`.
  - `CHECK (char_length(name) BETWEEN 2 AND 200)`، `CHECK (char_length(phone) BETWEEN 6 AND 32)`.
- **قيود مالية:** لا تُقرأ `wallet_balance` وتُكتب يدوياً؛ كل حركة عبر `WalletService::credit/debit`.
- **علاقات:** `hasMany orders` (`customer_id`)، `hasMany customer_addresses` (`customer_id`)، `hasOne credit config` (انظر أدناه)، ومنطقياً `LoyaltyAccount`/`Subscription`.

---

### `customer_addresses`  ← كان: `CustomerAddress`
> عناوين توصيل منظّمة متعددة **يديرها العميل من البوابة فقط**. تُحذف تتابعياً مع العميل. عنوان افتراضي واحد لكل عميل.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `legacy_cuid` | string(191) | نعم | — | unique |
| `customer_id` | bigint unsigned | لا | — | FK → `customers.id` (onDelete: CASCADE) — العميل المالك |
| `label` | string(40) | لا | — | تسمية العنوان ("المنزل"، "العمل") |
| `district` | string(80) | نعم | null | الحي |
| `street` | string(160) | نعم | null | الشارع |
| `details` | string(200) | نعم | null | تفاصيل إضافية |
| `is_default` | boolean | لا | false | العنوان الافتراضي (**واحد فقط لكل عميل**) |
| `created_at` | timestamp | نعم | — | يُستخدم للترتيب (الافتراضي أولاً ثم الأحدث) |

- **ملاحظة على الطوابع:** النموذج الأصلي بلا `updated_at` (`const UPDATED_AT = null`)؛ نُبقي `created_at` فقط وفاءً للأصل ولمنطق الترتيب.
- **فهارس/قيود:**
  - **فريد جزئي `(customer_id)` حيث `is_default = true`** (partial unique index) — يضمن عنواناً افتراضياً واحداً كحدّ أقصى لكل عميل على مستوى القاعدة (تعزيز للمعاملة التطبيقية).
  - فهرس `(customer_id, is_default, created_at)` للترتيب.
- **علاقات:** `belongsTo customers` (`customer_id`). الإدارة عبر `PortalController` (توكن بوابة، مقيَّد بـ `customer_id`).

---

## 3. الائتمان / الآجل

### `organization_credit_settings`  ← كان: `Setting` بمفتاح `credit.config:{orgId}` (JSON)
> إعداد الآجل على مستوى المنشأة. صف واحد لكل منشأة (بدل JSON في `Setting`). يعدّله SUPER_ADMIN فقط.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned | لا | auto | PK |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` (CASCADE) — **فريد** (صف واحد للمنشأة) |
| `is_enabled` | boolean | لا | false | هل الآجل مفعَّل للمنشأة (`enabled` سابقاً) |
| `default_limit` | decimal(14,2) | لا | 0.00 | السقف الائتماني الافتراضي (0 ≤ x ≤ 10,000,000) (`defaultLimit` سابقاً) |
| `created_at` | timestamp | نعم | — | |
| `updated_at` | timestamp | نعم | — | |

- **فهارس/قيود:**
  - **فريد `organization_id`** — صف واحد فقط لكل منشأة.
  - `CHECK (default_limit >= 0 AND default_limit <= 10000000)`.
- **علاقات:** `belongsTo organizations`. لا FK للعميل — السقف على مستوى العميل يبقى في `customers.credit_limit`.
- **ملاحظة (فجوة 02/§9):** هذه القيم تُخزَّن وتُتحقَّق شكلياً فقط ولا تُفرَض حالياً في أي مسار دفع. الجدول يوفّر بنية جاهزة إن أُنفِذ الإنفاذ لاحقاً (فحص `outstanding + newDeferred ≤ credit_limit` وربط `is_enabled` بإتاحة `DEFERRED`).

---

## 4. تحويلات من Setting-JSON

| المفتاح في `Setting` (JSON) | صار في المخطط الجديد | ملاحظات |
|---|---|---|
| `credit.config:{orgId}` → `{ enabled, defaultLimit }` | جدول `organization_credit_settings` (`is_enabled`, `default_limit`) | صف واحد لكل منشأة بدل key/value؛ قيود CHECK على المدى. |
| `catalog.config:{orgId}` → `{ combinedPricingMode }` (`'sum'` / `'independent'`) | عمود `combined_pricing_mode` على جدول `organizations` (خارج نطاق هذا الملف — يُضاف في مخطط المنشآت) | enum: `sum` / `independent` (CHECK)، افتراضي `independent`. مذكور هنا للتتبّع فقط لأنه إعداد كتالوج؛ التنفيذ الفعلي في جدول المنشأة. |

> **ملاحظة نطاق:** `Organization.taxRate` (نسبة الضريبة، افتراضي 15) و`combined_pricing_mode` أعلاه من خصائص المنشأة ويُعرَّفان في مخطط المنشآت/الإعدادات، لا هنا. `wallet_balance` تُعرَّف حركاته في مخطط المدفوعات/المحفظة.

---

## 5. ملخص العلاقات

```
organizations ─┬─< service_categories ─< products ─< services >─ (order_items.service_id)
               │                    └──────────────< services (category_id منسوخ)
               ├─< garment_types           >─ (order_items.garment_type_id)
               ├─< units                   >─ (inventory_items.unit_id)
               ├─< customers ─┬─< customer_addresses
               │              └── (orders / loyalty / subscriptions)
               └─1─ organization_credit_settings

branches ─< customers (branch_id = فرع الإنشاء)
```

- **CASCADE** من `organizations` نحو الكيانات المملوكة، ومن `customers` نحو `customer_addresses`.
- **RESTRICT** على `services → products/categories` و`customers → branches` صيانةً لسلامة السجلات المرجعية (الطلبات، الفروع).
