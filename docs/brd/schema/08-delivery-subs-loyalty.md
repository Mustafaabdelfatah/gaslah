# مخطط الجداول 08 — التوصيل والاشتراكات والولاء

> يشتق هذا المخطط من ثلاثة مستندات BRD: `04-delivery.md` (التوصيل والسائقون)، و`06-subscriptions-loyalty.md` (اشتراكات العملاء والولاء)، و`07-portal.md` (بوابة العميل). **بوابة العميل لا تضيف أي جدول** — هي سطح قراءة/طلب فوق نفس كيانات التوصيل والاشتراكات والولاء ومحفظة العميل، بمصادقة `kind=customer` مستقلة؛ كل عملياتها (قائمة الطلبات، تفاصيل الطلب، العناوين، إنشاء/متابعة/موافقة التوصيل) تقرأ وتكتب في الجداول أدناه (وفي `customers`/`orders`/`customer_addresses` المعرّفة في مخططات أخرى). لذا لا يظهر للبوابة قسم جداول خاص.

## اصطلاحات هذا المخطط
- الجداول snake_case جمع؛ الأعمدة snake_case.
- المفتاح الأساسي `id` bigint auto-increment؛ وعمود `legacy_cuid` string nullable unique يحفظ المعرّف cuid الأصلي عند استيراد الصف من نماذج Prisma الحالية (للربط أثناء الهجرة).
- المفاتيح الأجنبية `{singular}_id` مع الجدول المرجعي وسلوك `onDelete` مذكور صراحة.
- `organization_id` / `branch_id` حسب نطاق الكيان.
- `created_at` / `updated_at` قياسيان ما لم يُذكر خلاف ذلك؛ طوابع سير العمل المخصّصة (assigned_at, accepted_at, completed_at …) أعمدة منفصلة nullable.
- الأموال `decimal(14,2)`؛ رصيد القطع `remaining_quota` عدد صحيح (قطع)، والرصيد المالي `remaining_balance` عشري.
- الحالات/الأنواع تُخزَّن `string` مع PHP enum مطابق وقيد `CHECK` على القيم المسموحة (بدل Postgres enum، لمرونة التطوّر مستقبلاً).
- `metadata` من نوع JSON للبيانات المرنة فقط — لا منطق مالي/حالة عليه.

---

## 1. جداول التوصيل

### `delivery_zones`  ← كان: `DeliveryZone`
> منطقة جغرافية بتسعير ثابت تابعة لفرع؛ رسومها تتجاوز تسعير التوصيل الذاتي عند اختيارها في الطلب.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | المفتاح الأساسي. |
| `legacy_cuid` | string | نعم | null | المعرّف cuid الأصلي (unique). |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade` (المنطقة تابعة للفرع). |
| `name` | string(120) | لا | — | اسم المنطقة (عربي). |
| `name_en` | string(120) | نعم | null | الاسم بالإنجليزية. |
| `fee` | decimal(14,2) | لا | 0 | رسوم التوصيل للمنطقة. |
| `postal_codes` | json | نعم | null | مصفوفة أكواد بريدية (بيانات مرجعية؛ غير مُدارة من واجهة الموظف حالياً). |
| `eta_minutes` | integer | نعم | null | زمن التوصيل التقديري بالدقائق. |
| `is_active` | boolean | لا | true | مفعّلة أم لا. |
| `sort_order` | integer | لا | 0 | ترتيب العرض (الجديدة تأخذ عدد المناطق الحالية للفرع). |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`legacy_cuid`)؛ index(`branch_id`)؛ index(`branch_id`, `is_active`) (جلب المناطق المفعّلة للفرع)؛ index(`branch_id`, `sort_order`) (ترتيب العرض).
- **علاقات:** `belongsTo branch`؛ `hasMany delivery_requests` (عبر `zone_id`).

---

### `drivers`  ← كان: `Driver`
> السائق — سائق منشأة (`is_platform=false`) أو سائق منصّة مشترك (`is_platform=true`)، مثبَّت دائماً لفرع.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade`. الفرع المنزل (non-null دائماً، حتى لسائق المنصّة). |
| `organization_id` | bigint | نعم | null | FK → `organizations.id`، `onDelete: cascade`. يُملأ لسائقي المنصّة بمنظمة المالك. |
| `user_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null`. ربط اختياري بمستخدم النظام. |
| `name` | string(120) | لا | — | اسم السائق. |
| `phone` | string(30) | لا | — | رقم الجوال — **فريد على مستوى النظام كلّه** (انظر القيود). |
| `vehicle` | string(120) | نعم | null | وصف المركبة. |
| `is_active` | boolean | لا | true | سائق غير مفعّل لا يُسنَد إليه ولا يدخل ولا يعمل. |
| `is_platform` | boolean | لا | false | سائق منصّة مشترك (`true`) أم سائق منشأة (`false`). |
| `coverage_region` | string(160) | نعم | null | منطقة تغطية سائق المنصّة (تُدار من لوحة المنصّة فقط). |
| `notes` | text | نعم | null | ملاحظات. |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`legacy_cuid`)؛ **unique(`phone`)** ← قاعدة فرادة الهاتف العالمية (BRD §8/20؛ تدعم حلّ السائق الفريد عند الدخول)؛ index(`branch_id`)؛ index(`organization_id`)؛ index(`is_platform`, `is_active`) (بناء قائمة السائقين المؤهّلين: منشأة/منصّة نشط)؛ index(`branch_id`, `is_active`).
- **علاقات:** `belongsTo branch`، `belongsTo organization`، `belongsTo user`؛ `hasMany delivery_requests`.

---

### `delivery_requests`  ← كان: `DeliveryRequest`
> الكيان المحوري — رحلة توصيل واحدة (استلام PICKUP أو تسليم DELIVERY). قيمة الإدخال `BOTH` تُترجَم إلى صفّين منفصلين عند الإنشاء ولا تُخزَّن كقيمة صفّ.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `branch_id` | bigint | لا | — | FK → `branches.id`، `onDelete: cascade`. الفرع المالك (نطاق المستأجر). |
| `customer_id` | bigint | لا | — | FK → `customers.id`، `onDelete: restrict`. العميل صاحب الطلب. |
| `order_id` | bigint | نعم | null | FK → `orders.id`، `onDelete: set null`. الفاتورة المرتبطة (تُملأ عند جرد السلة أو الربط اليدوي). |
| `driver_id` | bigint | نعم | null | FK → `drivers.id`، `onDelete: set null`. السائق المسند (فارغ في المتاح/الخارجي). |
| `zone_id` | bigint | نعم | null | FK → `delivery_zones.id`، `onDelete: set null`. منطقة التسعير المختارة. |
| `created_by_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null`. المستخدم المُنشئ (فارغ للبوابة). |
| `type` | string(16) | لا | — | نوع الرحلة (enum: PICKUP/DELIVERY). CHECK. |
| `status` | string(24) | لا | 'REQUESTED' | الحالة (enum؛ 7 حالات). CHECK. |
| `source` | string(12) | لا | 'STAFF' | المصدر (enum: STAFF/PORTAL). CHECK. |
| `fee` | decimal(14,2) | لا | 0 | رسوم التوصيل لهذه الرحلة. |
| `fee_applied_to_order` | boolean | لا | false | هل أُضيفت الرسوم كبند في الطلب؟ (معطّل عملياً حالياً؛ الرسوم تبقى منفصلة). |
| `address` | string(500) | لا | — | عنوان الاستلام/التسليم (نص حر). |
| `notes` | string(500) | نعم | null | ملاحظات الطلب. |
| `lat` | decimal(10,7) | نعم | null | خط العرض (لبناء رابط الخريطة). |
| `lng` | decimal(10,7) | نعم | null | خط الطول. |
| `scheduled_at` | timestamp | نعم | null | الموعد المجدول (null = "في أقرب وقت"). |
| `assigned_at` | timestamp | نعم | null | لحظة إسناد السائق/الخارجي. |
| `accepted_at` | timestamp | نعم | null | لحظة قبول المندوب (أو قبول مسبق). |
| `rejected_at` | timestamp | نعم | null | لحظة رفض المندوب. |
| `reject_reason` | string(300) | نعم | null | سبب الرفض. |
| `arrived_at` | timestamp | نعم | null | لحظة تأكيد وصول المندوب لموقع العميل. |
| `pickup_photo_url` | string(255) | نعم | null | اسم ملف صورة إثبات الاستلام (اسم فقط؛ الرابط الموقّع يُبنى وقت العرض). |
| `delivery_photo_url` | string(255) | نعم | null | اسم ملف صورة إثبات التسليم. |
| `inventory_done_at` | timestamp | نعم | null | لحظة إتمام جرد السلة وإنشاء الفاتورة. |
| `inventory_notes` | string(500) | نعم | null | ملاحظات الجرد. |
| `invoice_approval_required` | boolean | لا | false | هل تحتاج الفاتورة موافقة العميل قبل التسليم؟ |
| `invoice_approved_at` | timestamp | نعم | null | لحظة موافقة العميل على الفاتورة. |
| `external_provider` | string(60) | نعم | null | اسم مزوّد التوصيل الخارجي (الطريقة 3)؛ عند تعبئته لا سائق داخلي. |
| `external_ref` | string(120) | نعم | null | مرجع الطلب لدى المزوّد الخارجي. |
| `completed_at` | timestamp | نعم | null | لحظة بلوغ حالة نهائية (AT_FACILITY/DELIVERED/CANCELLED). |
| `metadata` | json | نعم | null | بيانات مرنة إضافية (لا منطق حالة عليها). |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`legacy_cuid`)؛
  - CHECK `type IN ('PICKUP','DELIVERY')`.
  - CHECK `status IN ('REQUESTED','ASSIGNED','PICKED_UP','AT_FACILITY','OUT_FOR_DELIVERY','DELIVERED','CANCELLED')`.
  - CHECK `source IN ('STAFF','PORTAL')`.
  - index(`branch_id`, `status`) (لوحة التوصيل وإحصائياتها)؛
  - index(`customer_id`) (طلبات العميل في البوابة)؛
  - index(`driver_id`, `status`) (طلبات المندوب المفتوحة أولاً؛ وحساب حمل السائق للإسناد التلقائي)؛
  - index(`order_id`)؛ index(`zone_id`)؛
  - index(`branch_id`, `type`, `status`) (فلاتر واجهة الموظف والإحصائيات لكل نوع)؛
  - index(`created_at`) و index(`scheduled_at`) (الترتيب الزمني والجدولة).
- **علاقات:** `belongsTo branch`, `customer`, `order`, `driver`, `zone`, `creator (user)`؛ `hasMany delivery_status_histories` (عبر `request_id`).

#### القيم المنطقية للحالة (`status`) حسب النوع
- **مسار PICKUP:** `REQUESTED → ASSIGNED → PICKED_UP → AT_FACILITY` (نهائية). + `CANCELLED` من أي حالة غير نهائية.
- **مسار DELIVERY:** `REQUESTED → ASSIGNED → PICKED_UP → OUT_FOR_DELIVERY → DELIVERED` (نهائية). + `CANCELLED`.
- الحالات النهائية `AT_FACILITY / DELIVERED / CANCELLED` تختم `completed_at`.

---

### `delivery_status_histories`  ← كان: `DeliveryStatusHistory`
> سجلّ تدقيق لكل تغيير حالة أو حدث جوهري (إنشاء، إسناد، قبول، رفض، وصول، جرد، انتقال، موافقة فاتورة). **بلا زوج created_at/updated_at** — عمود `at` وحيد فقط (`$timestamps = false`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `request_id` | bigint | لا | — | FK → `delivery_requests.id`، `onDelete: cascade`. |
| `user_id` | bigint | نعم | null | FK → `users.id`، `onDelete: set null`. المستخدم المُحدِث (فارغ لأفعال المندوب/البوابة/الآلي). |
| `from_status` | string(24) | نعم | null | الحالة السابقة (null لأول سجلّ). CHECK ضمن قيم status. |
| `to_status` | string(24) | لا | — | الحالة الجديدة. CHECK ضمن قيم status. |
| `note` | string(500) | نعم | null | وصف نصّي للحدث (عربي). |
| `at` | timestamp | لا | — | لحظة التغيير (الطابع الوحيد). |

- **فهارس/قيود:** unique(`legacy_cuid`)؛ index(`request_id`, `at`) (خط زمن الطلب مرتّباً)؛ CHECK على `from_status`/`to_status` ضمن نفس مجموعة قيم حالة الطلب.
- **علاقات:** `belongsTo delivery_request`، `belongsTo user`.
- **ملاحظة:** سجلّات `from_status === to_status` تُستخدم لتوثيق أحداث لا تغيّر الحالة (الوصول، الجرد، الموافقة).

---

### `delivery_settings`  ← كان: JSON في `Setting`
> صفّ إعدادات توصيل واحد لكل منظمة (بدل بلوب `delivery.settings:{orgId}`). يشمل الطرق المفعّلة، تسعير التوصيل الذاتي، مفاتيح سير العمل، وكتلة "المتاح" التي يضبطها مالك المنصّة (بدل `delivery.platformMethods:{orgId}`). تفصيل التحويل في القسم الأخير.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، `onDelete: cascade`. **unique** (صفّ واحد لكل منظمة). |
| `method_self_delivery` | boolean | لا | true | الطريقة 1 مفعّلة (سائقو المنشأة). |
| `method_platform_driver` | boolean | لا | false | الطريقة 2 مفعّلة (سائق المنصّة). |
| `method_integration` | boolean | لا | false | الطريقة 3 مفعّلة (تطبيق خارجي). |
| `available_self_delivery` | boolean | لا | true | أتاحتها المنصّة (للقراءة من قبل المنشأة). |
| `available_platform_driver` | boolean | لا | true | أتاحتها المنصّة. |
| `available_integration` | boolean | لا | false | أتاحتها المنصّة (تبدأ "قريباً"). |
| `self_fee_mode` | string(16) | لا | 'FLAT' | وضع تسعير التوصيل الذاتي (enum: FLAT/PER_DIRECTION). CHECK. |
| `self_flat_fee` | decimal(14,2) | لا | 0 | الرسوم الموحّدة (وضع FLAT). |
| `self_pickup_fee` | decimal(14,2) | لا | 0 | رسوم الاستلام (وضع PER_DIRECTION). |
| `self_delivery_fee` | decimal(14,2) | لا | 0 | رسوم التسليم (وضع PER_DIRECTION). |
| `self_hours_from` | string(5) | نعم | null | بداية ساعات العمل (تنسيق `H:i`). |
| `self_hours_to` | string(5) | نعم | null | نهاية ساعات العمل. |
| `self_slot_minutes` | integer | لا | 60 | طول شريحة الحجز بالدقائق (15–480). |
| `wf_portal_ordering` | boolean | لا | true | هل يطلب العملاء التوصيل من البوابة؟ |
| `wf_manual_assign` | boolean | لا | false | false=إسناد تلقائي، true=يدوي. |
| `wf_require_acceptance` | boolean | لا | true | يجب أن يقبل المندوب قبل العمل. |
| `wf_show_map` | boolean | لا | true | عرض رابط خرائط Google. |
| `wf_photo_proof` | boolean | لا | false | طلب صور إثبات. |
| `wf_basket_inventory` | boolean | لا | true | عرض خطوة جرد السلة. |
| `wf_invoice_approval` | boolean | لا | false | طلب موافقة العميل على الفاتورة. |
| `wf_notify_whatsapp` | boolean | لا | true | إشعارات واتساب. |
| `wf_notify_sms` | boolean | لا | false | إشعارات SMS. |
| `metadata` | json | نعم | null | إعدادات إضافية مرنة مستقبلية. |
| `created_at` | timestamp | لا | — | |
| `updated_at` | timestamp | لا | — | |

- **فهارس/قيود:** unique(`organization_id`)؛ CHECK `self_fee_mode IN ('FLAT','PER_DIRECTION')`؛ CHECK `self_slot_minutes BETWEEN 15 AND 480`.
- **علاقات:** `belongsTo organization`.

---

## 2. جداول الاشتراكات

### `subscription_plans`  ← كان: `SubscriptionPlan`
> تعريف باقة الاشتراك (المنتج) المملوكة للمنشأة. تحدّد الدورة والنوع والسعر والقيمة المرجعية للتهيئة.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، `onDelete: cascade`. المنشأة المالكة (عزل). |
| `service_id` | bigint | نعم | null | FK → `services.id`، `onDelete: set null`. خدمة مرتبطة (معرّفة، بلا منطق شراء/استهلاك حالياً). |
| `name` | string(160) | لا | — | اسم الباقة المعروض. |
| `cycle` | string(16) | لا | — | دورة الباقة (enum: MONTHLY/QUARTERLY/YEARLY). CHECK. |
| `type` | string(24) | لا | — | نوع الباقة (enum: PIECE_QUOTA/PREPAID_BALANCE/UNLIMITED_SERVICE). CHECK. |
| `price` | decimal(14,2) | لا | 0 | سعر الباقة للفترة (≥0؛ صفر=مجّانية). |
| `quota` | decimal(14,2) | نعم | null | القيمة المرجعية للتهيئة: عدد القطع (PIECE_QUOTA) أو قيمة الرصيد (PREPAID_BALANCE)؛ مُهملة لـ UNLIMITED_SERVICE. |
| `auto_renew` | boolean | لا | true | يُنسَخ إلى الاشتراك عند الشراء (بلا منطق تجديد فعلي حالياً — فجوة). |
| `is_active` | boolean | لا | true | مفعّلة للبيع (لا يُفلتَر عليها عند الشراء حالياً — فجوة). |
| `metadata` | json | نعم | null | بيانات مرنة. |

- **أعمدة زمنية:** لا شيء (النموذج الأصلي `timestamps = false`). لا `created_at`/`updated_at`.
- **فهارس/قيود:** unique(`legacy_cuid`)؛ index(`organization_id`, `is_active`)؛ index(`organization_id`, `price`) (قائمة الباقات "الأرخص أولاً")؛ index(`service_id`)؛ CHECK `cycle IN ('MONTHLY','QUARTERLY','YEARLY')`؛ CHECK `type IN ('PIECE_QUOTA','PREPAID_BALANCE','UNLIMITED_SERVICE')`؛ CHECK `price >= 0`.
- **علاقات:** `belongsTo organization`، `belongsTo service`؛ `hasMany subscriptions`.
- **دلالة الدورة:** MONTHLY=1، QUARTERLY=3، YEARLY=12 شهراً (منطق `endAt`).

---

### `subscriptions`  ← كان: `Subscription`
> نسخة العميل من الباقة — الفترة والأرصدة الأولية المشتقّة من النوع. **مُصمَّم لدعم التجديد/الإلغاء/التجميد** (أعمدة `auto_renew`, `canceled_at`, `frozen_at`) لسدّ فجوات النظام القديم الذي لم ينفّذها.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `customer_id` | bigint | لا | — | FK → `customers.id`، `onDelete: cascade`. العميل المشترِك. |
| `plan_id` | bigint | لا | — | FK → `subscription_plans.id`، `onDelete: restrict` (لا تُحذف باقة لها اشتراكات). |
| `status` | string(16) | لا | 'ACTIVE' | حالة الاشتراك (enum: ACTIVE/FROZEN). CHECK. |
| `start_at` | timestamp | لا | — | بداية الفترة (لحظة الشراء). |
| `end_at` | timestamp | نعم | null | نهاية الفترة = `start_at + CYCLE_MONTHS`. |
| `remaining_quota` | integer | نعم | null | رصيد القطع المتبقي (لباقات PIECE_QUOTA فقط). |
| `remaining_balance` | decimal(14,2) | نعم | null | الرصيد المالي المتبقي (لباقات PREPAID_BALANCE فقط). |
| `auto_renew` | boolean | لا | true | مُنسوخ من الباقة عند الشراء (يقود منطق التجديد المستقبلي). |
| `frozen_at` | timestamp | نعم | null | **جديد (سدّ فجوة):** لحظة التجميد؛ فارغ = غير مجمّد. يرافق `status=FROZEN`. |
| `canceled_at` | timestamp | نعم | null | **جديد (سدّ فجوة):** لحظة الإلغاء؛ فارغ = غير مُلغى. |
| `renewed_from_id` | bigint | نعم | null | **جديد (سدّ فجوة):** FK → `subscriptions.id` (ذاتي)، `onDelete: set null`. الاشتراك السابق الذي جُدِّد منه هذا (سلسلة تجديد). |
| `metadata` | json | نعم | null | بيانات مرنة. |
| `created_at` | timestamp | لا | — | (النموذج الأصلي يحمل `createdAt` فقط.) |

- **أعمدة زمنية:** `created_at` فقط (لا `updated_at` — الأصل `const UPDATED_AT = null`). طوابع السير (`start_at`/`end_at`/`frozen_at`/`canceled_at`) أعمدة منفصلة.
- **فهارس/قيود:** unique(`legacy_cuid`)؛ index(`customer_id`, `status`, `start_at`) (جلب أحدث اشتراك فعّال للعميل تحت قفل عند الاستهلاك)؛ index(`plan_id`)؛ index(`status`, `end_at`) (كنس التجديد/الانتهاء مستقبلاً)؛ index(`renewed_from_id`)؛ CHECK `status IN ('ACTIVE','FROZEN')`.
- **علاقات:** `belongsTo customer`، `belongsTo plan`، `belongsTo renewedFrom (self)`؛ `hasMany renewals (self)`؛ يُشار إليه من `orders.subscription_id` (انظر أدناه).

> **تهيئة الأرصدة عند الشراء:** PIECE_QUOTA → `remaining_quota = plan.quota`, `remaining_balance = null`؛ PREPAID_BALANCE → `remaining_balance = plan.quota`, `remaining_quota = null`؛ UNLIMITED_SERVICE → كلاهما null.

---

### مرجع الاشتراك على الطلبات — عمود على `orders`
> ليس جدولاً جديداً، لكنه جزء من نموذج الاشتراكات: الطلب يعرف أي اشتراك دفع عنه (لتمكين ردّ القطع/الرصيد عند إلغاء الطلب مستقبلاً).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `subscription_id` | bigint | نعم | null | FK → `subscriptions.id`، `onDelete: set null`. يُكتب عند الاستهلاك في POS بطريقة الدفع SUBSCRIPTION. |

- **فهرس مقترح على `orders`:** index(`subscription_id`).
- **ملاحظة enum:** استهلاك الاشتراك لا يُنشئ صفّ `payments` (لا قيمة `SUBSCRIPTION` في enum طريقة الدفع)؛ يُميَّز بالقيد المحاسبي (`source=PAYMENT`, `refType=SubscriptionConsume`) وبهذا العمود.

---

## 3. جداول الولاء

### `loyalty_programs`  ← كان: `LoyaltyProgram`
> برنامج ولاء واحد فعّال لكل منشأة. يحدّد معدّل الكسب وقيمة النقطة ومدة الصلاحية.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `organization_id` | bigint | لا | — | FK → `organizations.id`، `onDelete: cascade`. المنشأة المالكة. |
| `name` | string(120) | لا | — | اسم البرنامج (طول أدنى حرفان). |
| `earn_rate` | decimal(14,2) | لا | 1 | معدّل الكسب (0–10000). معرّف بلا كسب تلقائي حالياً — فجوة. |
| `point_value` | decimal(14,2) | لا | 0.10 | قيمة النقطة بالعملة عند الاستبدال (0–10000). |
| `expiry_months` | integer | نعم | null | مدة صلاحية النقاط بالأشهر (1–120). معرّف بلا منطق انتهاء فعلي — فجوة. |
| `is_active` | boolean | لا | true | هل البرنامج مفعّل. |
| `metadata` | json | نعم | null | بيانات مرنة. |

- **أعمدة زمنية:** لا شيء (الأصل بلا timestamps).
- **فهارس/قيود:** unique(`legacy_cuid`)؛ index(`organization_id`, `is_active`) (قراءة البرنامج الأول حسب `is_active` تنازلياً)؛ CHECK `earn_rate BETWEEN 0 AND 10000`؛ CHECK `point_value BETWEEN 0 AND 10000`؛ CHECK `expiry_months IS NULL OR expiry_months BETWEEN 1 AND 120`.
- **علاقات:** `belongsTo organization`؛ `hasMany loyalty_accounts`.
- **ملاحظة:** فرض "برنامج واحد لكل منشأة" منطقيّ في المتحكّم (يقرأ الأول)؛ يمكن تقويته بـ unique جزئي على (`organization_id`) حيث `is_active=true` إن رُغب.

---

### `loyalty_accounts`  ← كان: `LoyaltyAccount`
> حساب نقاط العميل — **واحد لكل عميل** (فريد). يحمل الرصيد الحالي والإجمالي التراكمي.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `customer_id` | bigint | لا | — | FK → `customers.id`، `onDelete: cascade`. **unique** (حساب واحد لكل عميل). |
| `program_id` | bigint | لا | — | FK → `loyalty_programs.id`، `onDelete: restrict`. البرنامج المتبوع. |
| `tier_id` | bigint | نعم | null | معرّف شريحة/مستوى (معرّف بلا منطق شرائح — فجوة). لا FK حالياً. |
| `points_balance` | decimal(14,2) | لا | 0 | رصيد النقاط الحالي القابل للاستبدال (**لا يهبط تحت الصفر** — قاعدة مفروضة). |
| `lifetime_points` | decimal(14,2) | لا | 0 | إجمالي النقاط المكتسبة على العمر (يزيد فقط بالإضافات الموجبة، لا ينقص). |
| `metadata` | json | نعم | null | بيانات مرنة. |

- **أعمدة زمنية:** لا شيء (الأصل بلا timestamps).
- **فهارس/قيود:** unique(`legacy_cuid`)؛ **unique(`customer_id`)** ← حساب واحد لكل عميل؛ index(`program_id`)؛ index(`points_balance`) (قائمة الأرصدة الأعلى أولاً)؛ CHECK `points_balance >= 0`.
- **علاقات:** `belongsTo customer`، `belongsTo program`؛ `hasMany loyalty_transactions`.

---

### `loyalty_transactions`  ← كان: `LoyaltyTransaction`
> حركة نقاط واحدة (كسب/استبدال/مكافأة/انتهاء). موجب للإضافة، سالب للخصم/الاستبدال.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto-inc) | لا | — | |
| `legacy_cuid` | string | نعم | null | cuid الأصلي (unique). |
| `account_id` | bigint | لا | — | FK → `loyalty_accounts.id`، `onDelete: cascade`. |
| `order_id` | bigint | نعم | null | FK → `orders.id`، `onDelete: set null`. الطلب المرتبط (للكسب على الطلبات؛ غير مكتوب حالياً — فجوة). |
| `type` | string(12) | لا | — | نوع الحركة (enum: EARN/REDEEM/BONUS/EXPIRE). CHECK. |
| `points` | decimal(14,2) | لا | — | مقدار النقاط (موجب للإضافة، سالب للخصم/الاستبدال). |
| `note` | string(300) | نعم | null | ملاحظة نصية (سبب الحركة). |
| `expires_at` | timestamp | نعم | null | تاريخ انتهاء صلاحية نقاط الحركة (معرّف بلا منطق يقرأه — فجوة). |
| `created_at` | timestamp | لا | — | (الأصل يحمل `createdAt` فقط.) |

- **أعمدة زمنية:** `created_at` فقط (لا `updated_at`).
- **فهارس/قيود:** unique(`legacy_cuid`)؛ index(`account_id`, `created_at`) (خط زمن حركات الحساب)؛ index(`order_id`)؛ CHECK `type IN ('EARN','REDEEM','BONUS','EXPIRE')`.
- **علاقات:** `belongsTo loyalty_account`، `belongsTo order`.
- **ملاحظة enum:** الاستخدام الفعلي حالياً: `BONUS` للتعديل اليدوي الموجب، `REDEEM` للاستبدال والتعديل اليدوي السالب؛ `EARN`/`EXPIRE` معرّفان وغير مُستخدَمين (أُبقيا في القيد لدعم الكسب التلقائي وانتهاء الصلاحية مستقبلاً — سدّ فجوة). النطاق المطلوب في المهمة يذكر EARN/REDEEM/BONUS؛ أُضيف EXPIRE أمانةً للنموذج الأصلي.

---

## 4. تحويلات من Setting-JSON (إعدادات التوصيل)

النظام القديم يخزّن إعدادات التوصيل كبلوبات JSON في جدول `Setting` (key/value). يحوّلها هذا المخطط إلى جدول `delivery_settings` أعلاه، بصفّ واحد لكل منظمة، وفق الخريطة التالية:

### 4.1 من `delivery.settings:{orgId}` → أعمدة `delivery_settings`
| مسار المفتاح في JSON | العمود الجديد | ملاحظة |
|---|---|---|
| `methods.selfDelivery` | `method_self_delivery` | boolean. |
| `methods.platformDriver` | `method_platform_driver` | boolean. |
| `methods.integration` | `method_integration` | boolean. |
| `self.feeMode` | `self_fee_mode` | FLAT/PER_DIRECTION + CHECK. |
| `self.flatFee` | `self_flat_fee` | decimal(14,2). |
| `self.pickupFee` | `self_pickup_fee` | decimal(14,2). |
| `self.deliveryFee` | `self_delivery_fee` | decimal(14,2). |
| `self.hoursFrom` | `self_hours_from` | نص `H:i`. |
| `self.hoursTo` | `self_hours_to` | نص `H:i`. |
| `self.slotMinutes` | `self_slot_minutes` | integer 15–480. |
| `workflow.portalOrdering` | `wf_portal_ordering` | boolean. |
| `workflow.manualAssign` | `wf_manual_assign` | boolean. |
| `workflow.requireAcceptance` | `wf_require_acceptance` | boolean. |
| `workflow.showMap` | `wf_show_map` | boolean. |
| `workflow.photoProof` | `wf_photo_proof` | boolean. |
| `workflow.basketInventory` | `wf_basket_inventory` | boolean. |
| `workflow.invoiceApproval` | `wf_invoice_approval` | boolean. |
| `workflow.notifyWhatsapp` | `wf_notify_whatsapp` | boolean. |
| `workflow.notifySms` | `wf_notify_sms` | boolean. |

### 4.2 من `delivery.platformMethods:{orgId}` → أعمدة "المتاح"
> يضبطها مالك المنصّة فقط (توفّر الطرق للمنشأة). كانت مفتاحاً منفصلاً حتى لا تدهسها المنشأة.

| مسار المفتاح في JSON | العمود الجديد | افتراضي |
|---|---|---|
| `selfDelivery` | `available_self_delivery` | true |
| `platformDriver` | `available_platform_driver` | true |
| `integration` | `available_integration` | false ("قريباً") |

**قاعدة الحفظ المحفوظة:** المنشأة لا تفعّل إلا طريقة متاحة؛ عند الحفظ تُجبَر أي طريقة غير متاحة على off (`method_X = requested AND available_X`) — الحفظ ينجح دائماً ويستقر على حالة صالحة.

### 4.3 ملاحظة على `portal.config:{orgId}` (بوابة العميل)
> البوابة لا تضيف جداول. مفاتيح branding العامة (`showOffers`, `termsUrl`, `privacyUrl`) لا تزال ملائمة كإعدادات منظمة خفيفة؛ تبقى في `Setting`/OrgStore أو تُحوَّل ضمن جدول إعدادات المنظمة العام (خارج نطاق هذا المخطط) — لا علاقة لها بجداول التوصيل/الاشتراكات/الولاء هنا.

---

## 5. ملخص القيود الفريدة والعلاقات المحورية
- **`drivers.phone` فريد عالمياً** — يُدعِّم حلّ السائق الفريد النشط عند الدخول (فشل مغلق على الغموض).
- **`loyalty_accounts.customer_id` فريد** — حساب ولاء واحد لكل عميل.
- **`delivery_settings.organization_id` فريد** — صفّ إعدادات واحد لكل منظمة.
- **`orders.subscription_id`** — مرجع set-null يربط الطلب بالاشتراك الذي دفع عنه.
- **سلسلة تجديد الاشتراك** — `subscriptions.renewed_from_id` (ذاتي) لدعم التجديد الذي لم ينفّذه النظام القديم.
- سلوك `onDelete`: `cascade` للسجلّات التابعة (histories, transactions, settings)؛ `restrict` لمنع حذف باقة/برنامج له تبعات؛ `set null` للمراجع الاختيارية (order, driver, zone, user, service).
