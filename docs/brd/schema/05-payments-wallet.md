# مخطط الجداول — 05 المدفوعات والمحفظة والتسويات

> اشتقاق كامل من `docs/brd/05-payments-wallet-payouts.md`. يصف الجداول المرشّحة للإضافة في نموذج
> Laravel (snake_case، PK bigint تزايدي، FK صريحة) مع أسماء الموديلات القديمة (Prisma/PascalCase)
> التي كانت مصدرها. **هذا تصميم مرجعي فقط — لا تعديل على أي كود أو أي هجرة فعلية.**

## اصطلاحات عامة مطبّقة على كل الجداول
- الأسماء snake_case جمع؛ الأعمدة snake_case.
- `id` bigint auto-increment مفتاح أساسي.
- `legacy_cuid` string nullable unique — لتخزين معرّف cuid القديم عند استيراد صف موجود من قاعدة Prisma.
- FK باسم `{singular}_id` مع الجدول المرجعي و`onDelete` المذكور صراحةً.
- `organization_id` / `branch_id` حسب نطاق الكيان.
- `created_at` / `updated_at` (تُذكر أي استثناءات لكل جدول — بعض المصادر بلا `updatedAt`).
- الأموال `decimal(14,2)`؛ النِسَب `decimal(5,2)`.
- الحالات/الأنواع: عمود `string` + PHP enum (backed by string) + قيد `CHECK` بالقيم المذكورة.

---

## ملاحظة: كيانات عديمة الحالة — لا جدول لها

- **رابط الدفع (`PayToken`)** توكن HMAC-SHA256 موقّع بلا حالة، صيغته `{oid, exp}~signature`. الحمولة معرّف
  الطلب فقط، والمبلغ يُعاد حسابه خادمياً من الطلب دائماً. **لا يُخزَّن في قاعدة البيانات — لا جدول له.**
- **توكنات إثبات OTP (`kind:pos-otp`)** توكنات موقّعة مؤقتة بُنيت على customerId+orgId، تُحرَق عبر
  `TokenDenylist` (قفل استشاري)، لا تُخزَّن كصفوف. **لا جدول لها.**
- **رصيد المحفظة** ليس جدولاً بل عمود على العميل — انظر «إضافة إلى customers» أدناه.

---

## إضافة إلى جدول `customers` (رصيد المحفظة)

> رصيد المحفظة الجاري يُخزَّن كعمود مباشر على العميل (مصدر الحقيقة المقفول `SELECT … FOR UPDATE`).
> **عمود إضافي واحد فقط** (تغيير إضافي non-breaking):

| العمود | النوع | Null | افتراضي | ملاحظات |
|---|---|---|---|---|
| `wallet_balance` | decimal(14,2) | لا | 0 | الرصيد الجاري؛ يُقرأ/يُكتب حصراً داخل معاملة `WalletService` تحت قفل صفّي. غير قابل للإسناد الكتلي عمداً. |

- **قيود:** يُفضَّل `CHECK (wallet_balance >= 0)` (لا رصيد سالب — الخصم محروس بفحص كفاية).
- **ملاحظة:** يُحدَّث فقط داخل نفس معاملة إدراج صفّ `wallet_transactions` فلا ينحرف عن `balance_after`.

---

## الجداول

### `payments`  ← كان: `Payment`
> دفعة واحدة مُحصَّلة على طلب. (المصدر بلا `updatedAt` — الموديل يعطّل عمود التحديث.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK. |
| `legacy_cuid` | string | نعم | null | unique؛ cuid الأصلي عند الاستيراد. |
| `order_id` | bigint | لا | — | FK → `orders.id`، onDelete: cascade (الدفعة تتبع الطلب). |
| `method` | string | لا | — | enum `PaymentMethod` — انظر أدناه. |
| `amount` | decimal(14,2) | لا | — | المبلغ المُحصَّل (مقرّب لخانتين). |
| `reference` | string | نعم | null | مرجع نصّي؛ للبوابة `gateway:{txnId}` (مفتاح idempotency)، لليدوي مرجع حرّ. |
| `verify_mode` | string | نعم | null | يُملأ للبطاقة/التحويل اليدوي فقط: `CARD`/`TRANSFER`. |
| `shift_id` | bigint | نعم | null | FK → `shifts.id`، onDelete: set null (الوردية التي حصّلت — لمطابقة الدرج). |
| `via_gateway` | boolean | لا | false | `true` ⇒ حُصِّلت على حساب بوّابة المنصّة ⇒ تدخل بركة التسويات. `false` ⇒ درج المنشأة. |
| `settlement_id` | bigint | نعم | null | FK → `payout_settlements.id`، onDelete: set null. null = غير مسوّاة بعد. |
| `created_at` | timestamp | لا | now | وقت الإنشاء. (لا `updated_at`.) |

- **enum `PaymentMethod`** (قيد صارم — لا تُضاف قيم): `CASH` · `CARD` · `TRANSFER` · `WALLET` · `DEFERRED`.
  ملاحظة: `DEFERRED` علامة حالة لا تحصيل — لا يُنشأ لها صفّ `payments` إطلاقاً (تُعلِّم حالة الطلب فقط).
- **فهارس/قيود:**
  - `CHECK (method IN ('CASH','CARD','TRANSFER','WALLET','DEFERRED'))`.
  - `CHECK (verify_mode IS NULL OR verify_mode IN ('CARD','TRANSFER'))`.
  - **فريد `reference`** (مرجع البوابة `gateway:{txnId}`) — يمنع ازدواج تسوية نفس عملية المزوّد (مفتاح idempotency).
    يُطبَّق كفهرس **جزئي فريد** `WHERE reference IS NOT NULL` (اليدوي قد يترك المرجع فارغاً/مكرّراً).
  - فهرس على `order_id` (تجميع دفعات الطلب).
  - فهرس على `shift_id` (مطابقة الدرج/الوردية).
  - **فهرس البركة غير المسوّاة:** فهرس جزئي `(via_gateway, settlement_id)` أو
    `WHERE via_gateway = true AND settlement_id IS NULL` — استعلام البركة الأساسي.
  - فهرس على `settlement_id` (مدفوعات الدفعة + الإطلاق).
- **علاقات:** ينتمي إلى `orders`، إلى `shifts` (اختياري)، إلى `payout_settlements` (اختياري).

---

### `wallet_transactions`  ← كان: `WalletTransaction`
> صفّ دفتر لحركة محفظة واحدة (شحن/سحب/استرجاع). (المصدر بلا `updatedAt` — `timestamps=false`.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK. |
| `legacy_cuid` | string | نعم | null | unique؛ cuid الأصلي عند الاستيراد. |
| `customer_id` | bigint | لا | — | FK → `customers.id`، onDelete: cascade (صاحب المحفظة). |
| `type` | string | لا | — | enum نوع حركة المحفظة — انظر أدناه. |
| `amount` | decimal(14,2) | لا | — | مبلغ الحركة (موجب دائماً؛ الاتجاه يحدّده `type`). |
| `balance_after` | decimal(14,2) | لا | — | الرصيد الجاري بعد الحركة — يُكتب داخل نفس المعاملة تحت القفل فلا ينحرف. |
| `ref_id` | bigint | نعم | null | ربط المصدر: طلب/استرجاع (`orders.id`) / استبدال ولاء (`accounts.id`) / null للشحن اليدوي. مرجع متعدّد الأنواع — يُترك عموداً حرّاً بلا FK صارمة (polymorphic). |
| `note` | string | نعم | null | وصف بشري بالعربي. |
| `created_at` | timestamp | لا | now | وقت الحركة. (لا `updated_at`.) |

- **enum `type` (نوع حركة المحفظة):** `TOPUP` (شحن/إيداع — الوحيد الذي ينشر قيداً محاسبياً عند `postAccounting=true`) ·
  `DEBIT` (سحب/صرف — محروس بفحص كفاية الرصيد المقفول) · `REFUND` (استرجاع — يُستدعى بـ `postAccounting=false`).
- **فهارس/قيود:**
  - `CHECK (type IN ('TOPUP','DEBIT','REFUND'))`.
  - `CHECK (amount > 0)` (المبلغ موجب دائماً).
  - فهرس على `(customer_id, created_at DESC)` — سجل الحركة أحدث أولاً (`history`).
  - فهرس على `ref_id` (تتبّع المصدر).
- **علاقات:** ينتمي إلى `customers`. `ref_id` مرجع متعدّد الأنواع (طلب/حساب) بلا قيد مرجعي مفروض.

---

### `online_charges`  ← كان: `OnlineCharge`
> دفتر الشحنات الإلكترونية (مرآة البوابة). مصدر مراقب مدفوعات المنصّة الحصري. (فيه `createdAt` و`updatedAt`.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK. |
| `legacy_cuid` | string | نعم | null | unique؛ cuid الأصلي عند الاستيراد. |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، onDelete: cascade (المنشأة المالكة، تُشتقّ من فرع الطلب). |
| `provider` | string | لا | — | مزوّد البوابة: `moyasar` / `stub` (أو `services.payment.driver`). |
| `provider_ref` | string | نعم | null | معرّف عملية المزوّد (نفسه `txnId`). |
| `purpose` | string | لا | — | enum الغرض — انظر أدناه. |
| `order_id` | bigint | نعم | null | FK → `orders.id`، onDelete: set null (لشحنات دفع الطلب). |
| `customer_id` | bigint | نعم | null | FK → `customers.id`، onDelete: set null. |
| `subscription_id` | bigint | نعم | null | FK → `subscriptions.id` (أو `platform_subscriptions.id`)، onDelete: set null (لشحنات الاشتراك). |
| `amount` | decimal(14,2) | لا | — | المبلغ. |
| `currency` | string | لا | 'SAR' | العملة. |
| `status` | string | لا | — | enum حالة الشحنة — انظر أدناه. |
| `idempotency_key` | string | نعم | null | يعيد استخدام `Payment.reference` (`gateway:{txnId}`) — مفتاح إزالة تكرار الويبهوك. |
| `raw_status` | string | نعم | null | الحالة الخام من المزوّد (`paid`/`refunded`…). |
| `created_at` | timestamp | لا | now | وقت الإنشاء. |
| `updated_at` | timestamp | لا | now | وقت آخر تحديث. |

- **enum `status` (حالة الشحنة):** `INITIATED` · `PAID` · `FAILED` · `REFUNDED`.
- **enum `purpose` (الغرض):** `ORDER_PAYMENT` (دفع طلب) · `SUBSCRIPTION` (دفع اشتراك منصّة).
- **فهارس/قيود:**
  - `CHECK (status IN ('INITIATED','PAID','FAILED','REFUNDED'))`.
  - `CHECK (purpose IN ('ORDER_PAYMENT','SUBSCRIPTION'))`.
  - **فريد `provider_ref`** (فريد لكل عملية مزوّد — يمنع الازدواج): فهرس جزئي فريد `WHERE provider_ref IS NOT NULL`.
  - **فريد `idempotency_key`**: فهرس جزئي فريد `WHERE idempotency_key IS NOT NULL` (مفتاح إزالة تكرار الويبهوك).
  - فهرس على `(organization_id, created_at DESC)` — سجل المنشأة/المراقب أحدث أولاً.
  - فهرس على `status` و`purpose` (مرشّحات المراقب).
  - فهرس على `order_id`، `customer_id`، `subscription_id`.
- **علاقات:** ينتمي إلى `organizations`؛ اختيارياً إلى `orders` / `customers` / `subscriptions`.

---

### `payout_settlements`  ← كان: `PayoutSettlement`
> دفعة تسوية بنكية تجمع مدفوعات البوابة وتُحوَّل للمنشأة بعد موافقة متعددة. (فيه `createdAt` و`updatedAt`.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK. |
| `legacy_cuid` | string | نعم | null | unique؛ cuid الأصلي عند الاستيراد. |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، onDelete: cascade (المنشأة المستفيدة). |
| `status` | string | لا | 'PENDING_APPROVAL' | enum حالة التسوية — انظر أدناه. |
| `urgent` | boolean | لا | false | `true` إذا طلب عاجل من المنشأة. |
| `period_start` | timestamp | نعم | null | أقدم `created_at` بين مدفوعات الدفعة. |
| `period_end` | timestamp | نعم | null | أحدث `created_at` بين مدفوعات الدفعة. |
| `payment_count` | integer | لا | 0 | عدد المدفوعات المحجوزة. |
| `gross_amount` | decimal(14,2) | لا | 0 | إجمالي المدفوعات (قبل الرسوم). |
| `fee_amount` | decimal(14,2) | لا | 0 | رسوم التحويل (≤ الإجمالي، ≥ 0). |
| `net_amount` | decimal(14,2) | لا | 0 | الصافي = الإجمالي − الرسوم. |
| `currency` | string | لا | 'SAR' | العملة. |
| `bank_snapshot` | json | نعم | null | لقطة بنك المنشأة وقت الإنشاء (iban/bankName/beneficiary) — تُخفَّى لغير مُنفِّذ التحويل. |
| `requested_by_id` | bigint | نعم | null | FK → `users.id`، onDelete: set null (مالك المنشأة طالب التسوية العاجلة). |
| `created_by_id` | bigint | نعم | null | FK → `admins.id` (أو `users.id`)، onDelete: set null. الأدمن المُنشئ — لا يحقّ له التصويت (maker-checker). |
| `approved_at` | timestamp | نعم | null | وقت اكتمال الموافقات. |
| `sent_by_id` | bigint | نعم | null | FK → `admins.id`، onDelete: set null (مَن سجّل التحويل). |
| `sent_at` | timestamp | نعم | null | وقت التحويل. |
| `transfer_ref` | string | نعم | null | رقم الحوالة البنكية عند markSent. |
| `note` | string | نعم | null | ملاحظة إنشاء. |
| `rejected_reason` | string | نعم | null | سبب الرفض. |
| `created_at` | timestamp | لا | now | وقت الإنشاء. |
| `updated_at` | timestamp | لا | now | وقت آخر تحديث. |

- **enum `status` (حالة التسوية):** `PENDING_APPROVAL` · `APPROVED` · `SENT` · `REJECTED` · `CANCELLED`.
  الحالتان المفتوحتان (`OPEN_STATUSES`) = `PENDING_APPROVAL` + `APPROVED`.
- **فهارس/قيود:**
  - `CHECK (status IN ('PENDING_APPROVAL','APPROVED','SENT','REJECTED','CANCELLED'))`.
  - `CHECK (fee_amount >= 0 AND fee_amount <= gross_amount)`.
  - `CHECK (net_amount = gross_amount - fee_amount)`.
  - **فهرس جزئي فريد لدفعة مفتوحة واحدة لكل منشأة** `payout_settlements_org_open_key`:
    `UNIQUE (organization_id) WHERE status IN ('PENDING_APPROVAL','APPROVED')` — يمنع دفعتين مفتوحتين معاً (يسند القفل الاستشاري، خطأ 23505 → 409).
  - فهرس على `(organization_id, created_at DESC)` — قائمة الدفعات للمنشأة.
  - فهرس على `status` (مرشّح الأدمن).
- **علاقات:** ينتمي إلى `organizations`؛ له عدّة `payments` محجوزة (`settlement_id`)؛ له عدّة `settlement_approvals`؛
  يشير إلى `users`/`admins` (requested_by/created_by/sent_by).

---

### `settlement_approvals`  ← كان: `SettlementApproval`
> صوت أدمن واحد على تسوية (maker-checker). (append-only، `createdAt` فقط.)

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint | لا | auto | PK. |
| `legacy_cuid` | string | نعم | null | unique؛ cuid الأصلي عند الاستيراد. |
| `settlement_id` | bigint | لا | — | FK → `payout_settlements.id`، onDelete: cascade (الدفعة المُصوَّت عليها). |
| `admin_id` | bigint | لا | — | FK → `admins.id` (أو `users.id`)، onDelete: cascade (الأدمن المُصوِّت). |
| `decision` | string | لا | — | enum القرار — انظر أدناه. |
| `note` | string | نعم | null | ملاحظة/سبب. |
| `created_at` | timestamp | لا | now | وقت التصويت. (لا `updated_at` — append-only.) |

- **enum `decision` (قرار الصوت):** `APPROVE` · `REJECT`.
- **فهارس/قيود:**
  - `CHECK (decision IN ('APPROVE','REJECT'))`.
  - **صوت واحد لكل أدمن لكل تسوية:** `UNIQUE (settlement_id, admin_id)` — التصويت المزدوج يصطدم بـ 23505 → 409.
  - فهرس على `settlement_id` (تجميع أصوات الدفعة + عدّها).
- **علاقات:** ينتمي إلى `payout_settlements`، وإلى `admins`/`users` (المُصوِّت).

---

## ملخّص القيود المفتاحية (تدقيق سريع)
1. **فهرس جزئي فريد لدفعة تسوية مفتوحة واحدة لكل منشأة** — `payout_settlements` على `organization_id WHERE status IN (PENDING_APPROVAL, APPROVED)`.
2. **صوت واحد لكل أدمن لكل تسوية** — `settlement_approvals` فريد مركّب `(settlement_id, admin_id)`.
3. **`balance_after` على `wallet_transactions`** — مكتوب داخل معاملة القفل.
4. **مرجع البوابة فريد لمنع الازدواج** — `payments.reference` و`online_charges.provider_ref` / `idempotency_key` (فهارس جزئية فريدة).
5. **JSON للـ snapshots** — `payout_settlements.bank_snapshot`.
6. **رصيد المحفظة عمود على customers** — `wallet_balance decimal(14,2) default 0`.
7. **كيانات عديمة الحالة بلا جداول** — `PayToken` (رابط الدفع) وتوكنات إثبات OTP.
