# مخطط الجداول 04 — الطلبات ونقطة البيع

> مشتق من BRD `03-orders-pos.md`. النطاق هنا: `orders`، `order_items`، `order_status_histories`، وقرار جدول حركات النقد/الفكة.
> جدول `payments` (سطور الدفع) مملوك لمجال المدفوعات/المحفظة — يُصمَّم في ملف المخطط 05، ويُشار إليه هنا فقط.
> جدول `otp_codes` مرجعٌ فقط — يُصمَّم في ملف مخطط المراسلة، ولا يُكرَّر هنا (انظر §5).

الكيانات القديمة (Prisma/PascalCase) تُعاد تسميتها إلى snake_case جمع، ومفاتيح الـ cuid النصّية تُنقل إلى `legacy_cuid` مع `id` bigint auto-increment جديد.

---

## 1. `orders`  ← كان: `Order`

> رأس الطلب: دورة حياته من الاستلام حتى التسليم والأرشفة، مع لقطة الإجماليات والضريبة وحالة الدفع ومفاتيح منع التكرار.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned، auto-increment | لا | — | المفتاح الأساسي |
| `legacy_cuid` | varchar(30) | نعم | null | cuid الأصلي عند الاستيراد؛ **unique** |
| `organization_id` | bigint unsigned | لا | — | FK → `organizations.id` · onDelete **restrict** · نطاق المنشأة (مشتق من الفرع، مُثبَّت للفهرسة المباشرة) |
| `branch_id` | bigint unsigned | لا | — | FK → `branches.id` · onDelete **restrict** · فرع الإنشاء (يُثبَّت من `branchId()`، لا يتأثر بمرشّح `X-Branch-Id`) |
| `customer_id` | bigint unsigned | لا | — | FK → `customers.id` · onDelete **restrict** · يجب أن يخصّ نفس المنشأة |
| `cashier_id` | bigint unsigned | نعم | null | FK → `users.id` · onDelete **set null** · مُنشئ الطلب (userId من المطالبات) |
| `subscription_id` | bigint unsigned | نعم | null | FK → `subscriptions.id` · onDelete **set null** · يُملأ فقط عند الدفع بالاشتراك (حاسم لاستعادة الحصة عند الإلغاء) |
| `order_no` | varchar(40) | لا | — | رقم مقروء: `{كود الفرع}-{YYYYMMDD}-{4 خانات}` · فريد لكل فرع |
| `barcode` | varchar(40) | لا | — | باركود المسح: `{YYYYMMDD}{كود الفرع}{تسلسل}` بعد إزالة غير الأبجدي-الرقمي · **unique** |
| `status` | varchar(20) | لا | `'RECEIVED'` | enum سير العمل (§4.1) + CHECK |
| `priority` | varchar(12) | لا | `'NORMAL'` | enum الأولوية (§4.2) + CHECK |
| `payment_status` | varchar(12) | لا | `'UNPAID'` | enum حالة الدفع (§4.3) + CHECK |
| `due_at` | timestamptz | نعم | null | موعد الاستحقاق/التسليم المتوقّع |
| `notes` | varchar(1000) | نعم | null | ملاحظات الطلب (حد 1000) |
| `subtotal` | decimal(14,2) | لا | 0 | مجموع الأسطر + رسم استعجال السلة |
| `discount_total` | decimal(14,2) | لا | 0 | قيمة الخصم (≤ subtotal دائماً) |
| `tax_total` | decimal(14,2) | لا | 0 | ضريبة على الصافي بعد الخصم |
| `tax_rate` | decimal(5,2) | لا | 15.00 | نسبة الضريبة كلقطة لحظة الإنشاء (ZATCA) |
| `grand_total` | decimal(14,2) | لا | 0 | الصافي + الضريبة |
| `paid_total` | decimal(14,2) | لا | 0 | المُحصَّل فعلياً حتى الآن |
| `delivery_fee` | decimal(14,2) | لا | 0 | رسم التوصيل (يُملأ من مجال التوصيل، لا من POS) |
| `client_request_id` | varchar(80) | نعم | null | مفتاح idempotency للسلة · فريد ضمن `branch_id` |
| `delivered_at` | timestamptz | نعم | null | يُختم عند الانتقال إلى DELIVERED (أساس إحصاء «سُلّم اليوم» لا `updated_at`) |
| `archived_at` | timestamptz | نعم | null | يُختم عند DELIVERED+PAID معاً؛ يُصفَّر إذا انتفى أي شرط |
| `metadata` | jsonb | نعم | null | حقول إضافية غير مهيكلة فقط (لا قيم مالية/حالة) |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:**
  - PK: `id`.
  - **unique** `orders_legacy_cuid_unique (legacy_cuid)`.
  - **unique** `orders_branch_order_no_unique (branch_id, order_no)` — رقم الطلب فريد لكل فرع (منع تصادم الترقيم).
  - **unique** `orders_barcode_unique (barcode)` — باركود فريد عالمياً.
  - **unique جزئي** `orders_branch_client_request_unique (branch_id, client_request_id) WHERE client_request_id IS NOT NULL` — الحاجز الثاني لـ idempotency (يلتقط السباق المتزامن).
  - index `orders_org_status_index (organization_id, status)` — لوحات/تقارير الحالة لكل منشأة.
  - index `orders_branch_created_index (branch_id, created_at)` — قوائم الفرع الزمنية والأتمتة.
  - index `orders_branch_status_archived_index (branch_id, status, archived_at)` — سلال اللوحة النشطة (status IN + archived_at IS NULL).
  - index `orders_branch_delivered_index (branch_id, delivered_at)` — «سُلّم اليوم».
  - index `orders_customer_index (customer_id)`، `orders_subscription_index (subscription_id)`، `orders_cashier_index (cashier_id)`.
  - index `orders_payment_status_index (organization_id, payment_status)` — تجميع المستحقات/المدفوع.
  - CHECK: `status IN ('RECEIVED','PROCESSING','READY','DELIVERED','CANCELLED')`.
  - CHECK: `priority IN ('NORMAL','EXPRESS')`.
  - CHECK: `payment_status IN ('UNPAID','PARTIAL','PAID','DEFERRED')`.
  - CHECK: `subtotal >= 0 AND discount_total >= 0 AND discount_total <= subtotal AND tax_total >= 0 AND grand_total >= 0 AND paid_total >= 0`.
  - CHECK: `tax_rate >= 0 AND tax_rate <= 100`.
- **علاقات:**
  - `belongsTo` → `organizations`, `branches`, `customers`, `users` (cashier)، `subscriptions` (اختياري).
  - `hasMany` → `order_items` (البنود)، `payments` (الدفعات — ملف 05)، `order_status_histories` (سجل الأتمتة).
- **enum PHP:** `OrderStatus`, `OrderPriority`, `OrderPaymentStatus` (backed by string، مفاتيح TitleCase: `Received`, `Processing`… القيمة = الملصق الأصلي).

---

## 2. `order_items`  ← كان: `OrderItem`

> أسطر بنود الطلب: خدمة مُعاد تسعيرها من الكتالوج بسعر مثبّت وكمية وإجمالي سطر.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned، auto-increment | لا | — | المفتاح الأساسي |
| `legacy_cuid` | varchar(30) | نعم | null | cuid الأصلي؛ **unique** |
| `order_id` | bigint unsigned | لا | — | FK → `orders.id` · onDelete **cascade** · الطلب الأب |
| `service_id` | bigint unsigned | لا | — | FK → `services.id` · onDelete **restrict** · خلية سعر الخدمة (نفس المنشأة) |
| `garment_type_id` | bigint unsigned | نعم | null | FK → `garment_types.id` · onDelete **set null** · نوع القطعة (اختياري) |
| `is_express` | boolean | لا | false | يُعاد حسابه على الخادم من `service.isExpressAvailable` |
| `quantity` | decimal(12,2) | لا | — | الكمية (> 0، حد 100000) |
| `unit_price` | decimal(14,2) | لا | — | سعر الوحدة المثبّت من الكتالوج (`basePrice + expressSurcharge إن استعجالي`) |
| `line_total` | decimal(14,2) | لا | — | `round2(unit_price × quantity)` |
| `notes` | varchar(500) | نعم | null | ملاحظة السطر (حد 500) |
| `created_at` | timestamptz | نعم | null | الأصل بلا طوابع (Prisma `$timestamps=false`)؛ أُضيف للتدقيق فقط، nullable للاستيراد التاريخي |

- **فهارس/قيود:**
  - PK: `id`؛ **unique** `order_items_legacy_cuid_unique (legacy_cuid)`.
  - index `order_items_order_index (order_id)` — تحميل بنود الطلب.
  - index `order_items_service_index (service_id)` — تقارير استهلاك الخدمة.
  - CHECK: `quantity > 0 AND quantity <= 100000`.
  - CHECK: `unit_price >= 0 AND line_total >= 0`.
- **علاقات:**
  - `belongsTo` → `orders` (cascade)، `services`، `garment_types` (اختياري).
- **ملاحظة:** لا `updated_at`/`deleted_at` (السطور غير قابلة للتعديل بعد الإنشاء؛ يُعاد إنشاء الطلب لا تعديل بنوده). `created_at` اختياري للحفاظ على الوفاء بالأصل.

---

## 3. `order_status_histories`  ← كان: `OrderStatusHistory`

> سجل تدقيق لكل انتقال حالة **تلقائي** (الأتمتة). الانتقالات اليدوية تُسجَّل في `audit_logs` (action=`STATUS`) — انظر ملف مخطط التدقيق. عمود زمني واحد `at` (بلا created/updated).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint unsigned، auto-increment | لا | — | المفتاح الأساسي |
| `legacy_cuid` | varchar(30) | نعم | null | cuid الأصلي؛ **unique** |
| `order_id` | bigint unsigned | لا | — | FK → `orders.id` · onDelete **cascade** · الطلب |
| `user_id` | bigint unsigned | نعم | null | FK → `users.id` · onDelete **set null** · الفاعل — **null للأتمتة** |
| `from_status` | varchar(20) | نعم | null | الحالة قبل الانتقال + CHECK (نفس مجموعة status) |
| `to_status` | varchar(20) | لا | — | الحالة بعد الانتقال + CHECK |
| `note` | varchar(255) | نعم | null | الأتمتة تكتب «أتمتة (تحويل تلقائي)» |
| `at` | timestamptz | لا | now() | وقت تسجيل التغيير |

- **فهارس/قيود:**
  - PK: `id`؛ **unique** `order_status_histories_legacy_cuid_unique (legacy_cuid)`.
  - index `order_status_histories_order_at_index (order_id, at)` — بناء خط زمن نشاط الطلب مرتّباً.
  - CHECK: `from_status IN ('RECEIVED','PROCESSING','READY','DELIVERED','CANCELLED')` (يقبل NULL).
  - CHECK: `to_status IN ('RECEIVED','PROCESSING','READY','DELIVERED','CANCELLED')`.
- **علاقات:**
  - `belongsTo` → `orders` (cascade)، `users` (اختياري).
- **ملاحظة مصدر مزدوج:** دالة `activity()` تدمج هذا الجدول مع `audit_logs` في خط زمن موحّد مع إزالة تكرار دفاعية بمفتاح (الحالة الهدف | الثانية).

---

## 4. enums كاملة (string + PHP enum + CHECK)

| المجال | العمود/الموضع | القيم |
|---|---|---|
| `OrderStatus` | `orders.status`، `order_status_histories.from/to_status` | `RECEIVED` (تم الاستلام)، `PROCESSING` (قيد المعالجة)، `READY` (جاهز)، `DELIVERED` (تم التسليم)، `CANCELLED` (ملغي) |
| `OrderPriority` | `orders.priority` | `NORMAL` (عادي)، `EXPRESS` (مستعجل) |
| `OrderPaymentStatus` | `orders.payment_status` | `UNPAID` (غير مدفوع)، `PARTIAL` (جزئي)، `PAID` (مدفوع)، `DEFERRED` (آجل) |
| طرق الدفع — POS (`settlePayment`) | تُخزَّن على `payments.method` (ملف 05) | `CASH`، `CARD`، `TRANSFER`، `WALLET`، `DEFERRED`، `SUBSCRIPTION`\* |
| طرق الدفع — `storePayment` (دفع لاحق) | `payments.method` (ملف 05) | `CASH`، `CARD`، `TRANSFER`، `WALLET`، `DEFERRED` (لا SUBSCRIPTION) |
| `verifyMode` (بطاقة/تحويل) | `payments.verify_mode` (ملف 05) | `MANUAL`، `TERMINAL` |

> \* **SUBSCRIPTION لا يُنشئ سطر `payments`** (enum Postgres `PaymentMethod` لا يحوي القيمة). يُميَّز في المحاسبة بـ `refType='SubscriptionConsume'`. لذا `orders.subscription_id` هو أثر الدفع الوحيد للاشتراك على مستوى الطلب. تفاصيل طرق الدفع وأعمدة `payments` في ملف المخطط 05.

---

## 5. حركات النقد/الفكة و`otp_codes` (قرار تصميمي)

### 5.1 لا حاجة لجدول حركات نقد/فكة مخصّص
حسب BRD §5.4:
- **الفائض إلى CHANGE:** يُعاد نقداً و**لا قيد ولا صف له** — لا يُخزَّن (يبقى في الدرج ويُطابق يدوياً).
- **الفائض إلى WALLET:** يمرّ عبر `WalletService::credit(..., 'TOPUP', ..., 'CASH')` — يُسجَّل كحركة محفظة + قيد إيراد مؤجّل في مجال المحفظة/المحاسبة (ملفا 05/08)، لا في جدول طلبات.
- **المبلغ المُحصَّل والمكمّل (secondary):** يُسجَّل كسطور `payments` (ملف 05).

لذلك حركات النقد/الفكة مغطّاة بالكامل بـ `payments` + `wallet_ledger` (ملف 05)، ولا يُضاف جدول جديد في هذا المجال. (لو لزم لاحقاً تسوية درج الكاش لكل وردية فذلك كيان تشغيلي منفصل يُصمَّم في مجال التقارير/العمليات، لا هنا.)

### 5.2 `otp_codes` — مرجع فقط
تحقق العميل الحاضر (§6 من BRD: `purpose='POS_WALLET'`، bcrypt hash، صلاحية 5د، استعمال واحد، سقف 5 محاولات، مربوط بـ orgId+phone) يخزَّن في جدول `otp_codes`. **يُصمَّم بالكامل في ملف مخطط المراسلة** — لا يُكرَّر هنا. أثره على هذا المجال: توكن الإثبات `kind='pos-otp'` (بلا صف جدول — HMAC موقّع)، ويُحرَق jti عبر آلية denylist ذرّية قبل تحرّك المال (لا جدول مالي مخصّص لذلك في نطاقنا).

---

## 6. ملخّص العلاقات (نطاق هذا الملف)

```
organizations ─┐
branches ──────┼──< orders >──< order_items >── services
customers ─────┤        │                    └─ garment_types
users(cashier)─┘        ├──< order_status_histories >── users(actor)
subscriptions ─────────-┘└──< payments (ملف 05)
```

- `orders` هو الجذر؛ حذفه cascade يزيل `order_items` و`order_status_histories` (وسطور `payments` — يُقرَّر في ملف 05). لكن المراجع الأب (organization/branch/customer/service) بـ **restrict** (لا حذف كيان مرجعي وله طلبات).
- `subscription_id` و`cashier_id` بـ **set null** (فقدان المرجع لا يُفقد الطلب المالي).
