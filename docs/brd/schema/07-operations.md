# مخطط الجداول — مجال العمليات (Operations)

> يغطّي هذا المستند **الجزء التشغيلي فقط** من `09-reports-analytics-operations.md`: المخزون، الموردون، أوامر الشراء، حركة المخزون، الورديات، حركات الدرج النقدي، والتسوية البنكية.
>
> **التقارير والتحليلات ولوحة المعلومات لا جداول لها** — كلها **مشتقّة (derived)** تُجمّع في SQL من الطلبات/الدفعات/القيود القائمة (`selectRaw`/`groupBy`)، فلا كيان يُخزَّن لها. لذلك أُسقطت من هذا المخطط عمداً.
>
> **العمود `legacy_cuid`**: كل جدول له مقابل قديم في نظام Prisma الحالي (PascalCase, PK نصّي cuid). عند الاستيراد يُحفظ المعرّف القديم في `legacy_cuid` (string nullable unique) لربط الصفوف المهاجَرة، بينما المفتاح الأساسي الجديد `id` هو bigint auto-increment. الجداول المقترحة حديثاً (بلا مقابل قديم) تُترك `legacy_cuid` = null.
>
> **أنواع الأموال**: `decimal(14,2)`. **الكميات**: `decimal(12,2)`. **الحالات/الأنواع**: `string` + PHP enum + قيد `CHECK`.

---

## فهرس الجداول

| الجدول | كان (Prisma) | النطاق | الغرض المختصر |
|---|---|---|---|
| `inventory_items` | `InventoryItem` | branch | أصناف المخزون/المستلزمات |
| `units` | `Unit` | organization | وحدات القياس — **مُغطّاة في `schema/02-catalog.md`** (مرجع فقط) |
| `suppliers` | `Supplier` | organization | موردو المستلزمات |
| `purchase_orders` | `PurchaseOrder` | branch | أوامر الشراء (رأس) |
| `purchase_order_items` | `PurchaseOrderItem` | — (تابع للأمر) | بنود أمر الشراء |
| `inventory_movements` | **مقترح جديد** | branch | سجل حركة المخزون (دخول/خروج/تسوية) — يسدّ فجوة "لا ledger" |
| `shifts` | `Shift` | branch | ورديات الصرّاف (جلسة الدرج) |
| `cash_movements` | `CashMovement` | branch | حركات الدرج النقدي (IN/OUT) |
| `bank_reconciliations` | **مقترح جديد** (بدل `Setting`-JSON) | organization | رأس التسوية البنكية + رصيد الكشف |
| `bank_reconciliation_lines` | **مقترح جديد** (بدل `Setting`-JSON) | organization | ربط الأسطر البنكية المصفّاة بجلسة تسوية |
| أعمدة `cleared_*` على `journal_lines` | **مقترح جديد (additive)** | — | تعليم السطر البنكي كمصفّى مباشرة على الدفتر |

---

## 1. المخزون (Inventory)

### `inventory_items`  ← كان: `InventoryItem`
> صنف مخزون / مستلزم يُحفظ في فرع ويُقاس بوحدة. في القديم كان رقماً يدوياً بحتاً (`quantity` حقل حالي بلا سجل حركة).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | المفتاح الأساسي |
| `legacy_cuid` | string | نعم | null | معرّف Prisma القديم (unique) |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade` |
| `unit_id` | bigint | لا | — | FK → `units.id`، `onDelete: restrict` (لا تُحذف وحدة مستخدَمة) |
| `name` | string | لا | — | اسم الصنف (≥ 2 حرف) |
| `sku` | string | نعم | null | رمز الصنف |
| `cost` | decimal(14,2) | لا | 0 | تكلفة الوحدة (مال) |
| `quantity` | decimal(12,2) | لا | 0 | الكمية الحالية |
| `reorder_level` | decimal(12,2) | لا | 0 | حدّ إعادة الطلب |
| `is_active` | boolean | لا | true | نشط؟ |
| `created_at` | timestamp | لا | now | القديم له `createdAt` بلا `updatedAt` |
| `updated_at` | timestamp | نعم | null | **جديد** — يدعم تعديل الصنف (التعديل كان موجوداً فعلاً في الـ API) |

- **فهارس/قيود:**
  - `PRIMARY KEY (id)`
  - `UNIQUE (legacy_cuid)`
  - فهرس `(branch_id, is_active)` — قائمة الأصناف النشطة للفرع (الاستعلام الأساسي).
  - فهرس `(branch_id, name)` — الترتيب بالاسم في القائمة.
  - فهرس `(unit_id)` — لدعم قيد الـ FK والتحقق.
  - فهرس جزئي **low-stock**: `(branch_id) WHERE quantity <= reorder_level` لتسريع نقطة `low-stock` بدل مسح كامل. (بديلاً: عمود محسوب مخزَّن `is_low_stock` — لكن المقارنة العمودية تكفي.)
  - `CHECK (quantity >= 0)`, `CHECK (reorder_level >= 0)`, `CHECK (cost >= 0)`.
- **علاقات:** `belongsTo units` (unit_id)؛ `belongsTo branches` (branch_id)؛ `hasMany purchase_order_items` (item_id)؛ `hasMany inventory_movements` (item_id).
- **ملاحظة:** `low_stock` يبقى **محسوباً** (`quantity <= reorder_level`) في طبقة العرض، ليس عموداً مخزَّناً — مطابقة لقاعدة العمل 17.

---

### `units`  ← كان: `Unit`  — **مرجع فقط (لتفادي التكرار)**
> وحدة قياس المخزون (قطعة/لتر/كيلو)، مقيّدة بالمنشأة، بلا طوابع زمنية.

> ⚠️ **هذا الجدول مُصمَّم بالكامل في `schema/02-catalog.md`** (كيان مرجعي للكتالوج، تُقرأ وتُتحقَّق ضمن مجال المخزون لكنها لا تملك نقاط CRUD هنا). لا يُكرَّر تعريفه هنا لتجنّب مصدرَي حقيقة متضاربَين. يُذكر فقط لأن `inventory_items.unit_id` يشير إليه.
>
> الشكل المختصر للربط: `id` (PK bigint) · `legacy_cuid` (unique) · `organization_id` (FK → `organizations.id`, cascade) · `name` · `symbol` · `conversion_factor` decimal(12,4) · **بلا `created_at/updated_at`**. القيد الجوهري: `assertUnitInOrg` — الوحدة يجب أن تخصّ منشأة الصنف (قاعدة 18).

---

### `inventory_movements`  ← **مقترح جديد** (يسدّ فجوة "لا سجل حركة")
> سجل حركة كل صنف: دخول (شراء/استلام)، خروج (استهلاك/بيع/هدر)، تسوية جرد. النظام القديم **لم يكن يملكه إطلاقاً** (الكمية رقم حالي فقط بلا تاريخ ولا ربط بالمبيعات). هذا الجدول يجعل `inventory_items.quantity` **مشتقّاً من مجموع الحركات** بدل رقم يدوي، ويتيح ربط الاستهلاك بالمبيعات مستقبلاً.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | null دائماً (لا مقابل قديم) |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade` (منسوخ للتقييد السريع) |
| `item_id` | bigint | لا | — | FK → `inventory_items.id`، `onDelete: cascade` |
| `type` | string | لا | — | نوع الحركة (CHECK أدناه) |
| `quantity` | decimal(12,2) | لا | — | كمية الحركة (موجبة دائماً؛ الاتجاه من `type`) |
| `quantity_after` | decimal(12,2) | نعم | null | الرصيد بعد الحركة (لقطة تدقيق اختيارية) |
| `unit_cost` | decimal(14,2) | نعم | null | تكلفة الوحدة وقت الحركة (لتقييم المخزون) |
| `source` | string | نعم | null | مصدر الحركة: PURCHASE_ORDER / ORDER / MANUAL / ADJUSTMENT |
| `ref_type` | string | نعم | null | نوع المرجع (`PurchaseOrder`, `Order`, ...) |
| `ref_id` | bigint | نعم | null | معرّف المرجع (غير مقيّد بـ FF لتعدّد الأنواع؛ نمط polymorphic خفيف) |
| `user_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null` (من نفّذ الحركة) |
| `note` | string | نعم | null | ملاحظة |
| `created_at` | timestamp | لا | now | — |

- **قيم `type` (enum + CHECK):** `IN` (استلام/شراء)، `OUT` (استهلاك/بيع/هدر)، `ADJUSTMENT` (تسوية جرد قد تكون +/−).
  `CHECK (type IN ('IN','OUT','ADJUSTMENT'))`.
- **فهارس/قيود:**
  - `PRIMARY KEY (id)`
  - `UNIQUE (legacy_cuid)`
  - فهرس `(item_id, created_at)` — كشف حساب الصنف زمنياً (الاستعلام الأساسي).
  - فهرس `(branch_id, created_at)` — حركات الفرع.
  - فهرس `(ref_type, ref_id)` — التتبّع من أمر شراء/طلب.
  - `CHECK (quantity >= 0)`.
- **علاقات:** `belongsTo inventory_items` (item_id)؛ `belongsTo branches`؛ `belongsTo users`.
- **تكامل الرصيد:** كل حركة تُنشأ داخل معاملة تعدّل `inventory_items.quantity` بقفل صف الصنف (`SELECT … FOR UPDATE`) لتجنّب فقدان التحديث — نفس مبدأ `WalletService` للمحفظة.

---

## 2. الموردون وأوامر الشراء

### `suppliers`  ← كان: `Supplier`
> مورّد مستلزمات، مقيّد بالمنشأة. له `createdAt` بلا `updatedAt` في القديم.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | معرّف Prisma القديم (unique) |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، `onDelete: cascade` |
| `name` | string | لا | — | اسم المورّد (≥ 2 حرف) |
| `phone` | string | نعم | null | الفارغ يُحوَّل null |
| `email` | string | نعم | null | يُتحقَّق كبريد |
| `address` | string | نعم | null | — |
| `created_at` | timestamp | لا | now | — |
| `updated_at` | timestamp | نعم | null | **جديد** — يدعم تعديل المورّد (كان موجوداً في الـ API) |

- **فهارس/قيود:**
  - `PRIMARY KEY (id)` · `UNIQUE (legacy_cuid)`
  - فهرس `(organization_id, name)` — قائمة موردي المنشأة مرتّبة بالاسم.
- **علاقات:** `belongsTo organizations`؛ `hasMany purchase_orders` (supplier_id).

---

### `purchase_orders`  ← كان: `PurchaseOrder`
> رأس أمر شراء ضدّ مورّد لفرع. القديم كان **قراءة فقط** (لا إنشاء/استلام/ترحيل). المخطط الجديد يضيف `updated_at` وحقول استلام/ترحيل لدعم الفجوات الموثّقة.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | معرّف Prisma القديم (unique) |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade` |
| `supplier_id` | bigint | لا | — | FK → `suppliers.id`، `onDelete: restrict` (لا يُحذف مورّد له أوامر) |
| `status` | string | لا | 'DRAFT' | حالة الأمر (CHECK أدناه) |
| `total` | decimal(14,2) | لا | 0 | إجمالي الأمر (مال) — يُشتق من مجموع البنود |
| `received_at` | timestamp | نعم | null | ختم الاستلام (يُملأ عند الاستلام؛ يدعم الفجوة المستقبلية) |
| `received_by_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null` — **جديد** لتتبّع من استلم |
| `posted_journal_entry_id` | bigint | نعم | null | FK → `journal_entries.id`، `onDelete: set null` — **جديد**، قيد الشراء عند الترحيل |
| `note` | string | نعم | null | ملاحظة |
| `created_at` | timestamp | لا | now | — |
| `updated_at` | timestamp | نعم | null | **جديد** — يدعم تعديل الأمر |

- **قيم `status` (enum + CHECK):** `DRAFT` (مسودّة)، `ORDERED` (مُرسَل)، `PARTIALLY_RECEIVED` (استلام جزئي)، `RECEIVED` (مستلَم)، `CANCELLED` (ملغى).
  `CHECK (status IN ('DRAFT','ORDERED','PARTIALLY_RECEIVED','RECEIVED','CANCELLED'))`.
- **فهارس/قيود:**
  - `PRIMARY KEY (id)` · `UNIQUE (legacy_cuid)`
  - فهرس `(branch_id, created_at DESC)` — آخر 50 أمراً للفرع (الاستعلام الأساسي، الأحدث أولاً).
  - فهرس `(supplier_id)` — أوامر المورّد.
  - فهرس `(status)` — تصفية بالحالة.
  - `CHECK (total >= 0)`.
  - **قيد تناسق (اختياري, trigger/تطبيقي):** `received_at IS NOT NULL` عند `status IN ('RECEIVED','PARTIALLY_RECEIVED')`.
- **علاقات:** `belongsTo suppliers`؛ `belongsTo branches`؛ `hasMany purchase_order_items` (purchase_order_id)؛ `belongsTo journal_entries` (posted_journal_entry_id).

---

### `purchase_order_items`  ← كان: `PurchaseOrderItem`
> بند أمر شراء. القديم **بلا طوابع زمنية**. أُضيف `received_quantity` لدعم الاستلام الجزئي.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | معرّف Prisma القديم (unique) |
| `purchase_order_id` | bigint | لا | — | FK → `purchase_orders.id`، `onDelete: cascade` (حذف الأمر يحذف بنوده) |
| `item_id` | bigint | لا | — | FK → `inventory_items.id`، `onDelete: restrict` (لا يُحذف صنف له بنود) |
| `quantity` | decimal(12,2) | لا | — | الكمية المطلوبة |
| `received_quantity` | decimal(12,2) | لا | 0 | **جديد** — الكمية المستلمة فعلاً (للاستلام الجزئي) |
| `cost` | decimal(14,2) | لا | — | تكلفة الوحدة (مال) |

- **فهارس/قيود:**
  - `PRIMARY KEY (id)` · `UNIQUE (legacy_cuid)`
  - فهرس `(purchase_order_id)` — تحميل بنود الأمر (eager load).
  - فهرس `(item_id)` — دعم قيد الـ FK وتتبّع الصنف.
  - `CHECK (quantity >= 0)`, `CHECK (received_quantity >= 0)`, `CHECK (received_quantity <= quantity)`, `CHECK (cost >= 0)`.
  - **بلا `created_at/updated_at`** — مطابقة للقديم.
- **علاقات:** `belongsTo purchase_orders`؛ `belongsTo inventory_items` (item_id).
- **ملاحظة استلام:** استلام بند يُنشئ `inventory_movements` بنوع `IN` (كمية = المستلَم) ويزيد `inventory_items.quantity` — سدّاً لفجوة "لا خصم/زيادة عند الاستلام".

---

## 3. الورديات وحركات الدرج

### `shifts`  ← كان: `Shift`
> جلسة درج نقدي لصرّاف بين الفتح والإغلاق. **بلا `created_at/updated_at`**؛ لها طوابع مخصّصة `opened_at`/`closed_at` (يُكتب `opened_at` يدوياً).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | معرّف Prisma القديم (unique) |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade` |
| `user_id` | bigint | لا | — | FK → `users.id`، `onDelete: restrict` (لا يُحذف صرّاف له ورديات) |
| `opened_at` | timestamp | لا | — | **طابع مخصّص** (يُكتب يدوياً عند الفتح) |
| `closed_at` | timestamp | نعم | null | **طابع مخصّص**؛ null = الوردية مفتوحة |
| `opening_float` | decimal(14,2) | لا | 0 | العهدة النقدية الافتتاحية |
| `expected_cash` | decimal(14,2) | لا | 0 | النقد المتوقع (يُثبَّت عند الإغلاق فقط؛ حيّ محسوب قبله) |
| `actual_cash` | decimal(14,2) | نعم | null | النقد الفعلي المعدود عند الإغلاق |
| `variance` | decimal(14,2) | نعم | null | `actual_cash − expected_cash` (سالب=عجز، موجب=زيادة)، يُثبَّت عند الإغلاق |
| `note` | string | نعم | null | **جديد** — القديم يقبل `note` عند الإغلاق لكن **لا عمود له** فيُهمَل؛ هذا العمود يصلح الفجوة |

- **فهارس/قيود:**
  - `PRIMARY KEY (id)` · `UNIQUE (legacy_cuid)`
  - **⭐ فهرس جزئي فريد — وردية مفتوحة واحدة لكل كاشير** (الضامن الجوهري، كان `Shift_userId_open_key`):
    `CREATE UNIQUE INDEX shifts_user_open_key ON shifts (user_id) WHERE closed_at IS NULL;`
    — يمنع فتح ورديتين متزامنتين لنفس المستخدم حتى تحت السباق؛ انتهاكه يُترجَم إلى 422 لا 500 (قاعدة 19).
  - فهرس `(branch_id, opened_at DESC)` — سجل آخر 50 وردية للفرع.
  - فهرس `(branch_id, closed_at)` — إيجاد الوردية المفتوحة على الفرع.
- **علاقات:** `belongsTo branches`؛ `belongsTo users`؛ `hasMany payments` (shift_id)؛ `hasMany cash_movements` (shift_id).
- **ملاحظة `expected_cash`:** = `opening_float + cash_total + cash_top_ups + movements_in − movements_out` (قاعدة 20). المكوّنات مشتقّة حياً من `payments`/`cash_movements`/`journal_lines` (WALLET_TOPUP) ولا تُخزَّن إلا لحظة الإغلاق.

---

### `cash_movements`  ← كان: `CashMovement`
> حركة نقد يدوية على درج الوردية: إدخال (IN) أو إخراج (OUT) — إيداع، مصروف نثري، سحب. تدخل معادلة `expected_cash`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | معرّف Prisma القديم (unique) |
| `shift_id` | bigint | لا | — | FK → `shifts.id`، `onDelete: cascade` (حذف الوردية يحذف حركاتها) |
| `branch_id` | bigint | نعم | null | FK → `branches.id`، `onDelete: cascade` — **جديد** (منسوخ للتقييد المستأجري المباشر) |
| `user_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null` — من نفّذ الحركة |
| `type` | string | لا | — | اتجاه الحركة (CHECK أدناه) |
| `amount` | decimal(14,2) | لا | — | المبلغ (موجب دائماً؛ الاتجاه من `type`) |
| `reason` | string | نعم | null | سبب الحركة/الوصف |
| `created_at` | timestamp | لا | now | — |

- **قيم `type` (enum + CHECK):** `IN` (نقد داخل الدرج)، `OUT` (نقد خارج الدرج).
  `CHECK (type IN ('IN','OUT'))`.
- **فهارس/قيود:**
  - `PRIMARY KEY (id)` · `UNIQUE (legacy_cuid)`
  - فهرس `(shift_id, type)` — جمع `movements_in`/`movements_out` للوردية (الاستعلام الأساسي في `summarize`).
  - فهرس `(branch_id, created_at)` — حركات الفرع زمنياً.
  - `CHECK (amount >= 0)`.
- **علاقات:** `belongsTo shifts` (shift_id)؛ `belongsTo branches`؛ `belongsTo users`.

---

## 4. التسوية البنكية (Bank Reconciliation)

> **الوضع الحالي:** الحالة (معرّفات الأسطر المصفّاة + رصيد الكشف) مخزَّنة **JSON في جدول `Setting`** عبر `OrgStore` (مخزن `bankrecon`, مفتاح `state`) — بلا مخطط. هذا القسم يصمّم **البديل الجدولي المُطبَّع** الذي يرفع الحالة إلى الدفتر نفسه ويصمد للنمو.
>
> **التصميم الموصى به = مسارَان متكاملان:**
> **(أ)** أعمدة `cleared_*` **additive** على `journal_lines` — التعليم يعيش على السطر البنكي مباشرة (أسرع استعلام، لا JSON).
> **(ب)** جدول `bank_reconciliations` لرصيد الكشف + رأس الجلسة، و`bank_reconciliation_lines` كتفصيل ربط اختياري لجلسات مؤرشفة.
>
> **قاعدة جوهرية (8.1):** التسوية **على مستوى المنشأة كلها لا الفرع** — حساب بنكي واحد وكشف واحد. لذا `organization_id` هو نطاق جداول التسوية، وليس `branch_id`.

### أعمدة `cleared_*` على `journal_lines`  ← **مقترح جديد (additive على جدول قائم)**
> إضافة أعمدة **nullable/defaulted فقط** على `journal_lines` القائم (آمنة لعميل Prisma الحيّ لأنها غير مذكورة في قوائمه الصريحة — مطابقة لقاعدة "additive-only"). تحلّ محلّ مجموعة المعرّفات في JSON.

| العمود | النوع | Null | افتراضي | ملاحظات |
|---|---|---|---|---|
| `cleared` | boolean | لا | false | هل السطر مصفّى (مطابق للكشف)؟ |
| `cleared_at` | timestamp | نعم | null | لحظة التصفية |
| `cleared_by_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null` — من صفّى |
| `bank_reconciliation_id` | bigint | نعم | null | FK → `bank_reconciliations.id`، `onDelete: set null` — الجلسة التي صُفّي فيها |

- **فهرس:** فهرس جزئي `(account_id) WHERE cleared = true` — يجمع `cleared_balance` من الأسطر المصفّاة فقط دون مسح الدفتر كله (الدفتر التراكمي كامل قد يكون ضخماً).
- **ملاحظة:** `book_balance` (رصيد الدفتر التراكمي الكامل، قاعدة 24) يبقى مشتقّاً في SQL: `Σ debit − Σ credit` على **كل** أسطر حساب `BANK` — لا عمود له.

### `bank_reconciliations`  ← **مقترح جديد** (بدل `Setting`-JSON، حقل رصيد الكشف)
> رأس التسوية على مستوى المنشأة: رصيد الكشف المُدخل يدوياً + لقطة نتيجة عند الترحيل. **صفّ حيّ واحد "مفتوح" لكل (منشأة، حساب بنكي)** يحمل رصيد الكشف الجاري؛ الأصفار المُغلقة أرشيف.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `legacy_cuid` | string | نعم | null | null (لا مقابل قديم؛ الأصل JSON) |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، `onDelete: cascade` — **النطاق = المنشأة لا الفرع** |
| `account_id` | bigint | لا | — | FK → `accounts.id`، `onDelete: restrict` — حساب `BANK` النظامي |
| `statement_balance` | decimal(14,2) | لا | 0 | رصيد الكشف الفعلي (يُدخَل يدوياً؛ ضبطه يُسجَّل بمسار التدقيق — قاعدة 27) |
| `statement_date` | date | نعم | null | تاريخ الكشف |
| `status` | string | لا | 'OPEN' | حالة الجلسة (CHECK أدناه) |
| `reconciled_snapshot` | jsonb | نعم | null | **لقطة فقط**: cleared_balance/difference/reconciled لحظة الإغلاق (JSON للـ snapshots فقط) |
| `reconciled_at` | timestamp | نعم | null | لحظة إقفال الجلسة |
| `created_by_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null` |
| `created_at` | timestamp | لا | now | — |
| `updated_at` | timestamp | نعم | null | — |

- **قيم `status` (enum + CHECK):** `OPEN` (جارية)، `RECONCILED` (مسوّاة/مُقفلة).
  `CHECK (status IN ('OPEN','RECONCILED'))`.
- **فهارس/قيود:**
  - `PRIMARY KEY (id)` · `UNIQUE (legacy_cuid)`
  - **فهرس جزئي فريد — جلسة مفتوحة واحدة لكل (منشأة، حساب):**
    `CREATE UNIQUE INDEX bank_recon_open_key ON bank_reconciliations (organization_id, account_id) WHERE status = 'OPEN';`
  - فهرس `(organization_id, account_id, created_at DESC)` — تاريخ الجلسات.
- **علاقات:** `belongsTo organizations`؛ `belongsTo accounts` (account_id)؛ `hasMany bank_reconciliation_lines`؛ `hasMany journal_lines` (via bank_reconciliation_id).
- **`difference`/`reconciled` مشتقّان:** `difference = statement_balance − cleared_balance`؛ `reconciled = |difference| < 0.01` (قاعدة 26) — لا يُخزَّنان إلا كلقطة في `reconciled_snapshot` عند الإقفال.

### `bank_reconciliation_lines`  ← **مقترح جديد** (تفصيل اختياري)
> يربط كل سطر بنكي مصفّى بجلسة تسوية. **بديل/مكمّل** للعمود `journal_lines.bank_reconciliation_id`: يفيد إذا لزم الاحتفاظ بتاريخ تصفية مستقل عن الدفتر (مثلاً مطابقة سطر دفتري بسطر كشف خارجي)، أو تصفية أسطر عبر جلسات متعددة. للتطبيق البسيط تكفي أعمدة `cleared_*` على `journal_lines` ويُهمَل هذا الجدول.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | — |
| `bank_reconciliation_id` | bigint | لا | — | FK → `bank_reconciliations.id`، `onDelete: cascade` |
| `journal_line_id` | bigint | لا | — | FK → `journal_lines.id`، `onDelete: cascade` — السطر البنكي المصفّى |
| `cleared` | boolean | لا | true | حالة التصفية داخل الجلسة |
| `statement_ref` | string | نعم | null | مرجع سطر الكشف الخارجي المطابِق (اختياري) |
| `matched_at` | timestamp | لا | now | لحظة المطابقة |
| `matched_by_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null` |

- **فهارس/قيود:**
  - `PRIMARY KEY (id)`
  - **`UNIQUE (bank_reconciliation_id, journal_line_id)`** — لا يُصفّى السطر مرتين في نفس الجلسة.
  - فهرس `(journal_line_id)` — أثر تصفية السطر.
- **علاقات:** `belongsTo bank_reconciliations`؛ `belongsTo journal_lines` (journal_line_id)؛ `belongsTo users` (matched_by_id).
- **ملاحظة معرّفات ميتة (حالة خاصة 4):** بما أن `journal_line_id` FK بـ `cascade`، حذف سطر بنكي يزيل صف التصفية تلقائياً — يزول تلقائياً بند "المعرّفات الميتة" الذي كان JSON يعاني منه (`clearedCount` كان يتجاهلها يدوياً).

---

## 5. تحويلات من Setting-JSON

جدول الحالة المخزَّنة حالياً كـ JSON في `Setting`/`OrgStore` وأين تُرحَّل في هذا المخطط:

| مفتاح `Setting` القديم (`OrgStore`) | المحتوى JSON | الوجهة الجدولية الجديدة |
|---|---|---|
| مخزن `bankrecon`, مفتاح `state` → `clearedIds[]` | مجموعة معرّفات الأسطر البنكية المصفّاة | عمود `journal_lines.cleared` (+ `cleared_at/cleared_by_id/bank_reconciliation_id`)؛ وتفصيلاً `bank_reconciliation_lines` |
| مخزن `bankrecon`, مفتاح `state` → `statementBalance` | رصيد الكشف المُدخل يدوياً | عمود `bank_reconciliations.statement_balance` |
| مخزن `bankrecon`, مفتاح `state` → (نتيجة محسوبة) | difference/reconciled | **لا يُخزَّن** — مشتقّ في SQL؛ لقطة عند الإقفال في `bank_reconciliations.reconciled_snapshot` (JSON للقطات فقط) |

- **`note` وردية الإغلاق:** كان يُقبَل ويُهمَل (لا عمود) → صار عمود `shifts.note`.
- **لا automation config في هذا المجال:** لم يُرصَد أي إعداد أتمتة (automation) مخزَّن كـ JSON ضمن العمليات؛ لو وُجد لاحقاً فمكانه إعدادات المنشأة (`schema/13-settings.md`) لا هذا المجال.

---

## 6. ملخّص كل الفهارس (مرجع سريع)

| الجدول | الفهرس | النوع | الغرض |
|---|---|---|---|
| `inventory_items` | `(branch_id, is_active)` | عادي | قائمة الأصناف النشطة |
| `inventory_items` | `(branch_id, name)` | عادي | الترتيب بالاسم |
| `inventory_items` | `(unit_id)` | عادي | دعم FK/التحقق |
| `inventory_items` | `(branch_id) WHERE quantity <= reorder_level` | **جزئي** | low-stock |
| `inventory_items` | `(legacy_cuid)` | فريد | ربط الاستيراد |
| `inventory_movements` | `(item_id, created_at)` | عادي | كشف حساب الصنف |
| `inventory_movements` | `(branch_id, created_at)` | عادي | حركات الفرع |
| `inventory_movements` | `(ref_type, ref_id)` | عادي | التتبّع للمرجع |
| `suppliers` | `(organization_id, name)` | عادي | قائمة الموردين |
| `purchase_orders` | `(branch_id, created_at DESC)` | عادي | آخر 50 أمراً |
| `purchase_orders` | `(supplier_id)` / `(status)` | عادي | تصفية |
| `purchase_order_items` | `(purchase_order_id)` / `(item_id)` | عادي | تحميل البنود/التتبّع |
| `shifts` | `(user_id) WHERE closed_at IS NULL` | **جزئي فريد** | ⭐ وردية مفتوحة واحدة لكل كاشير |
| `shifts` | `(branch_id, opened_at DESC)` | عادي | سجل الورديات |
| `shifts` | `(branch_id, closed_at)` | عادي | إيجاد المفتوحة |
| `cash_movements` | `(shift_id, type)` | عادي | جمع IN/OUT للوردية |
| `cash_movements` | `(branch_id, created_at)` | عادي | حركات الفرع |
| `journal_lines` (additive) | `(account_id) WHERE cleared = true` | **جزئي** | جمع cleared_balance |
| `bank_reconciliations` | `(organization_id, account_id) WHERE status='OPEN'` | **جزئي فريد** | جلسة مفتوحة واحدة/منشأة |
| `bank_reconciliations` | `(organization_id, account_id, created_at DESC)` | عادي | تاريخ الجلسات |
| `bank_reconciliation_lines` | `(bank_reconciliation_id, journal_line_id)` | فريد | لا تصفية مكرّرة |
| `bank_reconciliation_lines` | `(journal_line_id)` | عادي | أثر التصفية |

> **الفهارس الجزئية الثلاثة الجوهرية** (بخطّ عريض) هي ضمانات سلامة لا مجرّد أداء: وردية مفتوحة واحدة/كاشير، جلسة تسوية مفتوحة واحدة/منشأة، وكفاءة low-stock/cleared_balance.
