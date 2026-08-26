# BRD 03 — الطلبات ونقطة البيع

> يفترض هذا الملف أنك قرأت `00-overview-architecture.md`. المحاسبة التفصيلية (القيود، الحسابات النظامية، التسويات) في الملف 08، والمحفظة (`WalletService`, credit/debit, DEFERRED_REVENUE) في الملف 05. نصف هنا نقاط اللمس فقط ونشير للتفاصيل هناك.

---

## 1. نظرة عامة

يغطّي هذا المجال دورة حياة الطلب من إنشائه في نقطة البيع (POS) حتى تسليمه وأرشفته، مروراً بالتحصيل بكل طرق الدفع، وتحقق العميل الحاضر برمز OTP قبل أي خصم من رصيده المخزّن، والأتمتة التي تقدّم الطلبات تلقائياً نحو الجاهزية، والإلغاء مع عكس القيود واسترداد المحفظة واستعادة حصة الاشتراك. كما يشمل اللوحة (kanban board) ومحطة المسح، وإشعار الطلب للعميل عبر واتساب/رسالة نصية، وحاسبة الجدوى العامة.

النظام منقول بأمانة عن نظام Next.js الأصلي (Strangler migration): كل معادلة تسعير أو ترقيم أو انتقال حالة تحاكي مصدرها في `src/lib/money.ts`, `src/lib/order-number.ts`, `src/lib/order-flow.ts`, `src/server/automation.ts`.

المكوّنات الرئيسة والملفات:
- **إنشاء الطلب:** `PosController::store` (نقطة البيع) و`OrderCreator::create` (خدمة مُستخرَجة يستعملها جرد سلة التوصيل لتوليد فاتورة).
- **تحقق العميل:** `PosOtpController` (request/verify).
- **تفاصيل الطلب والحالة والدفع:** `OrderDetailController`.
- **الأتمتة:** `AutomationSweeper`.
- **اللوحة والمسح:** `BoardController`.
- **الإشعار:** `OrderNotifyController`.
- **حاسبة الجدوى:** `FeasibilityController` (عامة، بلا مصادقة، بلا قاعدة بيانات).

مبدأ حاكم عبر كل الوحدة: **الإجماليات تُحسب على الخادم دائماً، وأسعار العميل لا يُوثق بها إطلاقاً** — تُعاد قراءة سعر كل بند من الكتالوج.

---

## 2. الكيانات

### 2.1 Order (جدول `Order`)

يمتد `PrismaModel` (PascalCase، مفتاح cuid نصّي، `$incrementing=false`).

الحقول القابلة للكتابة (`$fillable`) ومعناها:

| الحقل | المعنى |
|---|---|
| `id` | مفتاح cuid يُولّد بـ `PrismaModel::newCuid()` |
| `orderNo` | رقم الطلب المقروء بشرياً، فريد يومياً لكل فرع — نمط `{كود الفرع}-{YYYYMMDD}-{تسلسل 4 خانات}` |
| `barcode` | باركود فريد للمسح — `{YYYYMMDD}{كود الفرع}{تسلسل}` بعد إزالة كل ما ليس حرفاً/رقماً |
| `branchId` | الفرع الذي أُنشئ فيه الطلب (يُثبّت من `branchId()`، لا يتأثر بمرشّح القراءة `X-Branch-Id`) |
| `customerId` | العميل — يجب أن يخصّ نفس المنشأة |
| `cashierId` | مُنشئ الطلب (userId من مطالبات التوكن)؛ قد يكون null |
| `status` | حالة سير العمل — enum: `RECEIVED`, `PROCESSING`, `READY`, `DELIVERED`, `CANCELLED` |
| `priority` | الأولوية — enum: `NORMAL`, `EXPRESS` |
| `paymentStatus` | حالة الدفع — enum: `UNPAID`, `PARTIAL`, `PAID`, `DEFERRED` |
| `dueAt` | موعد الاستحقاق/التسليم المتوقّع (اختياري) |
| `notes` | ملاحظات الطلب (حد 1000 حرف) |
| `subtotal` | مجموع أسطر البنود + رسم الاستعجال على مستوى السلة |
| `discountTotal` | قيمة الخصم المطبّق (PERCENT أو FIXED، محدودة بألا تتجاوز subtotal) |
| `taxTotal` | ضريبة القيمة المضافة محسوبة على الصافي (بعد الخصم) |
| `taxRate` | نسبة الضريبة المُلتقطة لحظة الإنشاء (لقطة ZATCA — تُحفظ من `org->taxRate`، الافتراضي 15) |
| `grandTotal` | الإجمالي النهائي = الصافي + الضريبة |
| `paidTotal` | المُحصّل فعلياً حتى الآن |
| `deliveryFee` | رسم التوصيل (يُملأ من مجال التوصيل، ليس من POS) |
| `clientRequestId` | مفتاح idempotency للسلة (فريد ضمن `branchId`)؛ يمنع الفوترة المزدوجة عند إعادة مزامنة offline |
| `subscriptionId` | أي اشتراك سدّد هذه السلة — يُملأ فقط عند الدفع بالاشتراك، ليُمكِّن الإلغاء من استعادة الحصة/الرصيد المستهلك |

حقول أخرى موجودة على الجدول ويكتبها الكود مباشرة (خارج `$fillable`، عبر خصائص/`forceFill`):
- `deliveredAt` — يُختم عند الانتقال إلى DELIVERED (إحصاءات «سُلّم اليوم» تعتمد عليه لا على `updatedAt`).
- `archivedAt` — يُختم عندما يصبح الطلب DELIVERED و PAID معاً؛ يُصفّر إذا انتفى أي شرط.
- `createdAt`, `updatedAt` — طوابع زمنية قياسية.

العلاقات: `branch`, `customer`, `cashier` (belongsTo User)، `items` (hasMany OrderItem)، `payments` (hasMany Payment).

### 2.2 OrderItem (جدول `OrderItem`)

`public $timestamps = false` (لا createdAt/updatedAt في Prisma).

| الحقل | المعنى |
|---|---|
| `id` | cuid |
| `orderId` | الطلب الأب |
| `serviceId` | خلية سعر الخدمة (Service) المرجعية — يجب أن تخصّ نفس المنشأة |
| `garmentTypeId` | نوع القطعة (اختياري) — يُحوَّل اسمه عند العرض |
| `isExpress` | هل السطر استعجالي؟ (boolean) — يُعاد حسابه على الخادم من `svc->isExpressAvailable` |
| `quantity` | الكمية (float، > 0، حد أقصى 100000) |
| `unitPrice` | سعر الوحدة المُثبّت (يُعاد حسابه من الكتالوج، لا من العميل) |
| `lineTotal` | `round2(unitPrice × quantity)` |
| `notes` | ملاحظة السطر (حد 500) |

### 2.3 OrderStatusHistory (جدول `OrderStatusHistory`)

سجلّ تدقيق لكل انتقال حالة تلقائي (الأتمتة). `public $timestamps = false`؛ عمود زمني واحد `at`.

| الحقل | المعنى |
|---|---|
| `id` | cuid |
| `orderId` | الطلب |
| `userId` | الفاعل — `null` للأتمتة |
| `fromStatus` | الحالة قبل الانتقال |
| `toStatus` | الحالة بعد الانتقال |
| `note` | ملاحظة (الأتمتة تكتب «أتمتة (تحويل تلقائي)») |
| `at` | وقت تسجيل التغيير |

> ملاحظة مصدر مزدوج: الانتقالات **اليدوية** تُسجّل في `AuditLog` (action=`STATUS`) عبر `AuditTrail::log`، بينما الانتقالات **التلقائية** تُسجّل في `OrderStatusHistory`. تدمجهما دالة `activity()` في سجلّ نشاط موحّد مع إزالة تكرار دفاعية بمفتاح (الحالة الهدف | الثانية).

### 2.4 enums كاملة

- **status:** `RECEIVED` (تم الاستلام)، `PROCESSING` (قيد المعالجة)، `READY` (جاهز للاستلام)، `DELIVERED` (تم التسليم)، `CANCELLED` (ملغي).
- **priority:** `NORMAL` (عادي)، `EXPRESS` (مستعجل).
- **paymentStatus:** `UNPAID` (غير مدفوع)، `PARTIAL` (مدفوع جزئياً)، `PAID` (مدفوع بالكامل)، `DEFERRED` (آجل).
- **طرق الدفع (`payment.method` في POS):** `CASH`, `CARD`, `TRANSFER`, `WALLET`, `DEFERRED`, `SUBSCRIPTION`.
- **طرق الدفع في `storePayment` (OrderDetail):** `CASH`, `CARD`, `TRANSFER`, `WALLET`, `DEFERRED` (لا SUBSCRIPTION هنا).
- **`payment.verifyMode` (بطاقة/تحويل):** `MANUAL`, `TERMINAL`.
- **`payment.overpayTo` (فائض النقد):** `CHANGE` (يُعاد نقداً)، `WALLET` (يُضاف للمحفظة).
- **`payment.secondary.method` (مكمّل شحّ المحفظة):** `CASH`, `CARD`, `TRANSFER`.

> قيد enum على مستوى Postgres: `PaymentMethod` لا يحتوي قيمة `SUBSCRIPTION`، لذلك الدفع بالاشتراك **لا يُنشئ سطر Payment**؛ يُميَّز بـ `refType='SubscriptionConsume'` في القيد المحاسبي بدلاً من ذلك (انظر §5.6 والملف 08).

---

## 3. حساب الإجماليات (المعادلة الكاملة + الحدود)

المنطق في `PosController::computeTotals` (يحاكي `src/lib/money.ts#computeOrderTotals`). التقريب دائماً إلى منزلتين (`round($n, 2)`).

المدخلات: قائمة البنود `items` (بعد إعادة تسعيرها من الكتالوج)، رسم الاستعجال على مستوى السلة `expressSurcharge`، الخصم `discount` (اختياري)، نسبة الضريبة `taxRate`.

الخطوات بالترتيب الدقيق:

1. **مجموع الأسطر:** `lineSum = Σ (unitPrice × quantity)` لكل بند (بلا تقريب وسيط لكل سطر داخل الجمع؛ التقريب يقع على subtotal).
2. **الإجمالي الفرعي:** `subtotal = round2(lineSum + expressSurcharge)`. رسم الاستعجال هنا هو رسم **على مستوى السلة كلها** يُمرَّر مباشرة، منفصل عن `isExpress` لكل سطر (الذي يزيد `unitPrice` نفسه).
3. **الخصم:**
   - إذا `type === 'PERCENT'`: `discountTotal = round2(subtotal × (clamp(value, 0..100) / 100))` — النسبة محصورة بين 0 و100.
   - إذا `type === 'FIXED'`: `discountTotal = round2(min(value, subtotal))`.
   - ثم حدّ صارم إضافي: `discountTotal = min(discountTotal, subtotal)` — لا يتجاوز الخصم الإجمالي الفرعي أبداً (منعاً لإجمالي/ضريبة سالبة).
   - إن لم يوجد خصم: `discountTotal = 0`.
4. **الوعاء الخاضع للضريبة:** `taxableBase = round2(subtotal − discountTotal)`.
5. **الضريبة:** `taxTotal = round2(taxableBase × (taxRate / 100))` — **الضريبة تُحسب على الصافي بعد الخصم، لا على الإجمالي الفرعي**.
6. **الإجمالي النهائي:** `grandTotal = round2(taxableBase + taxTotal)`.

المخرجات: `subtotal, discountTotal, taxableBase, taxTotal, grandTotal`.

الحدود المطبّقة في مرحلة التحقق قبل الحساب:
- عدد البنود: 1 إلى 200 (يمنع تضخيم الذاكرة/قاعدة البيانات).
- الكمية لكل بند: > 0 وحتى 100000.
- سعر الوحدة المُدخل: 0 إلى 1,000,000 (لكنه يُستبدل بسعر الكتالوج على أي حال).
- `expressSurcharge`: ≥ 0.
- `discount.value`: ≥ 0.

> `OrderCreator::computeTotals` نسخة مبسّطة: `discountTotal = 0` دائماً (جرد التوصيل لا يطبّق خصماً)، والباقي مطابق (ضريبة على الصافي، تقريب لمنزلتين). لا يمرّر `expressSurcharge` على مستوى السلة.

---

## 4. إنشاء الطلب (POS store خطوة بخطوة)

`POST /pos/orders` → `PosController::store`.

### 4.1 البوابات المسبقة
1. `requirePermission($request, 'pos.checkout')` — يجب أن يملك المستخدم صلاحية الدفع الدقيقة (`StaffPermissions::has`). 403 إن غابت.
2. `requireActiveSubscription($request)` — اشتراك المنشأة يجب أن يكون نشطاً؛ تجربة منتهية/اشتراك غير نشط → 402 (قراءة فقط: يمكن العرض لا الإنشاء).
3. التحقق من المدخلات (customerId, priority, dueAt, notes, clientRequestId, discount, expressSurcharge, items[], payment{...}).

### 4.2 حلّ النطاق والفرع
4. `orgId = orgId($request)` (يفرض `assertStaff` ضمناً — يرفض توكن العميل/المورّد)، `branchId = branchId($request)`، `cashierId = claims.userId`.
5. جلب الفرع ضمن المنشأة: `Branch::where('id', branchId)->where('orgId', orgId)`. 404 إن لم يوجد.

### 4.3 idempotency (الحاجز الأول)
6. إن وُجد `clientRequestId` غير فارغ: ابحث عن طلب سابق بنفس `(branchId, clientRequestId)`. إن وُجد أَرجِعه بحالة 200 (لا فوترة مزدوجة عند إعادة مزامنة استجابةٍ ضاعت).

### 4.4 تحقق العميل والخدمات وإعادة التسعير
7. جلب العميل ضمن المنشأة: `Customer::where('id', customerId)->where('orgId', orgId)`. 404 إن لم يوجد (دفع المحفظة يخصم رصيده — customerId أجنبي = كتابة مال عبر المنشآت).
8. جلب كل الخدمات المرجعية ضمن المنشأة والتأكد أن عددها يطابق عدد المعرّفات الفريدة؛ 404 إن نقصت أي خدمة.
9. **إعادة التسعير الموثوق:** لكل بند — `express = isExpress المُرسل && svc->isExpressAvailable`؛ ثم `unitPrice = round2(svc->basePrice + (express ? svc->expressSurcharge : 0))`. يُهمَل سعر العميل تماماً.
10. `taxRate = org->taxRate ?? 15`؛ ثم استدعاء `computeTotals` (§3).

### 4.5 التحقق من صحة الدفع (قبل أي كتابة)
11. **CARD/TRANSFER بلا verifyMode:** 422 «لم يتم تأكيد الدفع» (المال يُحصَّل خارج التطبيق فيجب تأكيده MANUAL أو TERMINAL).
12. **verifyMode=TERMINAL بلا reference:** 422 «لم يتم استلام رمز عملية الشبكة — الدفع مرفوض» (بطاقة بلا رمز موافقة الشبكة = لم تُعتمد).
13. نفس الفحصين على `payment.secondary` إن وُجد (رسائل «الدفع الإضافي»).
14. **WALLET/SUBSCRIPTION → تحقق OTP الذرّي:** تحقّق توكن `payment.otpToken` بـ `ApiToken::verify`، ويجب أن يكون: `kind==='pos-otp'` و`customerId` مطابقاً للعميل و`orgId` مطابقاً، **و** `TokenDenylist::reserve($otpClaims)` تنجح (حرق الـ jti ذرّياً **قبل** تحرّك أي مال). إن فشل أيّها → 422 «يلزم تحقق العميل برمز OTP». (انظر §6 والنمط §4.1 في الملف 00.)

### 4.6 الإنشاء الذرّي مع إعادة المحاولة عند التصادم
15. حلقة حتى 6 محاولات: توليد `[orderNo, barcode]` بمعامل `attempt` (§4.8)، ثم `DB::transaction`:
    - إنشاء صف `Order` (status=`RECEIVED`, paymentStatus=`UNPAID`, paidTotal=0، مع كل الإجماليات و`taxRate` كلقطة، و`clientRequestId` إن وُجد).
    - إنشاء كل صفوف `OrderItem` مع `lineTotal = round2(unitPrice × quantity)`.
    - استدعاء `settlePayment($created, $customer, $payment)` (§5) داخل نفس المعاملة.
16. **معالجة `QueryException` رمز `23505` (تعارض فريد):**
    - إن كان التعارض على فهرس `(branchId, clientRequestId)`: يعني إعادة **متزامنة** لنفس السلة سبقت وأُودعت — أَرجِع الطلب الفائز بحالة 200 (الحاجز الثاني لـ idempotency، الذي لا يستطيع فحص-ثم-إدراج وحده منعه).
    - غير ذلك: تصادم `orderNo`/`barcode` — صفّر `$order` وأعِد المحاولة برفع التسلسل.
17. بعد 6 محاولات دون نجاح: 409 «تعذّر توليد رقم الطلب».

### 4.7 ما بعد الالتزام (best-effort، لا يوقف البيع)
18. `AccountingCore::syncOrderAccounting($order->id)` — يُنشر قيد البيع + الدفع بعد الالتزام (idempotent؛ الفشل يُسجَّل ولا يمنع البيع، وأمر backfill يعيد نشر المفقود). التفاصيل في الملف 08.
19. `WaService::trigger('orderCreated', $order)` — تأكيد واتساب «تم استلام الطلب» (مُقيَّد بمفتاح المنشأة + الحصة، معطّل افتراضياً، best-effort).
20. الاستجابة 201 مع تمثيل الطلب (`present`): id, orderNo, barcode, status, priority, paymentStatus, اسم العميل، الإجماليات، paidTotal، البنود.

### 4.8 توليد orderNo/barcode (`generateIdentifiers`)
- `datePart = YYYYMMDD` من الآن.
- `branchPrefix` = كود الفرع بعد رفعه لأحرف كبيرة وإزالة غير الأبجدي-الرقمي؛ إن كان فارغاً فآخر 4 محارف من id الفرع بأحرف كبيرة.
- `prefix = "{branchPrefix}-{datePart}-"`.
- أعلى تسلسل مستخدم اليوم لهذا البادئة (`orderNo LIKE prefix%` مرتّباً تنازلياً — **ليس عدّ صفوف**)، ثم `seq = lastSeq + 1 + attempt` (المعامل attempt يقفز فوق التصادم).
- `pad = zero-pad(seq, 4)`.
- `orderNo = "{prefix}{pad}"`.
- `barcode = إزالة غير الأبجدي-الرقمي من "{datePart}{branchPrefix}{pad}"`.

---

## 5. طرق الدفع (settlePayment — كل طريقة بالتفصيل)

`PosController::settlePayment($order, $customer, $payment)` يُستدعى داخل معاملة الإنشاء. يبدأ بحساب المتبقّي: `remaining = round2(grandTotal − paidTotal)`.

### 5.1 لا دفع (`$payment` فارغ)
- إن كان `remaining ≤ 0` (مثلاً إجمالي صفري) → `paymentStatus = PAID`. وإلا يبقى `UNPAID` (كما أُنشئ).

### 5.2 DEFERRED (آجل)
- لا سطر Payment ولا قيد. فقط: `paymentStatus = (remaining ≤ 0 ? PAID : DEFERRED)`.

### 5.3 remaining ≤ 0 لأي طريقة أخرى
- `paymentStatus = PAID` والخروج (لا يُحصَّل شيء).

### 5.4 CASH (نقدي — جزئي/فائض)
- المبلغ المُحصَّل الافتراضي `collect = remaining`.
- إن وُجد `payment.amount`: `tendered = round2(amount)`؛ يجب `tendered > 0` وإلا 422 «المبلغ المستلم غير صالح».
  - **جزئي:** إن `tendered < remaining` → `collect = tendered` (الطلب يصبح PARTIAL).
  - **فائض:** إن `tendered > remaining`:
    - إن `overpayTo === 'WALLET'` → الفائض `round2(tendered − remaining)` يُضاف للمحفظة عبر `WalletService::credit(..., 'TOPUP', ..., 'CASH')` (قيد Dr نقد / Cr إيراد مؤجّل — انظر الملف 05/08)، لأن كامل المبلغ يبقى في الدرج فيجب أن يطابق النقد المُحصَّل.
    - إن `overpayTo === 'CHANGE'` أو غير محدّد → الفائض يُعاد نقداً، **لا قيد له**.
- إنشاء سطر Payment (method, amount=collect، verifyMode=null للنقد، reference=null).
- تحديث `paidTotal += collect`؛ `paymentStatus = (collect ≥ remaining ? PAID : PARTIAL)`.

### 5.5 CARD / TRANSFER (بطاقة/تحويل)
- تصل هذه الطرق بعد اجتياز فحوص §4.5 (verifyMode مؤكّد، وreference موجود إن TERMINAL).
- `collect = remaining` (لا مبلغ جزئي عبر هذه الطرق في POS).
- إنشاء سطر Payment مع `verifyMode` (المُرسَل) و`reference` (بعد trim، أو null).
- تحديث paidTotal و paymentStatus.

### 5.6 SUBSCRIPTION (`settleWithSubscription`)
- جلب اشتراك العميل النشط: `status='ACTIVE'` و(`endAt` فارغ أو ≥ الآن)، الأحدث `startAt`، مع `lockForUpdate` و`plan`. 422 «لا يوجد اشتراك فعّال للعميل» إن غاب.
- التأكد أن الخطة مدفوعة: `plan->price ≤ 0` (مجانية) أو `SubscriptionController::subscriptionPaid($sub)`. وإلا 422 «الاشتراك غير مدفوع — يلزم تحصيله أولاً».
- الاستهلاك حسب نوع الخطة:
  - **PIECE_QUOTA:** `pieces = Σ quantity` لكل البنود؛ إن `remainingQuota < pieces` → 422 «رصيد قطع الاشتراك غير كافٍ (المتبقي: X)»؛ وإلا `remainingQuota -= pieces`.
  - **PREPAID_BALANCE:** إن `remainingBalance < remaining` → 422 «رصيد الاشتراك غير كافٍ (المتبقي: X)»؛ وإلا `remainingBalance -= remaining`.
  - **UNLIMITED_SERVICE:** الفترة الصالحة تكفي — لا خصم من عدّاد.
- تحديث الطلب: `paidTotal += remaining`، `paymentStatus = PAID`، **و`subscriptionId = sub->id`** (حاسم: بدونه لا يستطيع الإلغاء استعادة الحصة/الرصيد، فيخسر العميل ما دفعه).
- قيد الاستهلاك (idempotent على id الطلب): `source='PAYMENT', refType='SubscriptionConsume', refId=order->id`، سطران: Dr `DEFERRED_REVENUE` / Cr `AR` بقيمة remaining. **لا سطر Payment** (لعدم وجود قيمة enum). التفاصيل في الملف 08.

### 5.7 WALLET (`settleWithWallet`)
- قراءة مقفولة للرصيد: `SELECT walletBalance FROM Customer WHERE id=... FOR UPDATE` (لا يُوثق برصيد النموذج القديم).
- `walletPart = round2(min(balance, remaining))`؛ إن `≤ 0` → 422 «رصيد المحفظة غير كافٍ».
- خصم عبر `WalletService::debit($customer, walletPart, "دفع سلة ...", order->id)` (قفل صف + كتابة داخل معاملة — انظر الملف 05). إنشاء سطر Payment (method=WALLET، amount=walletPart).
- `rest = round2(remaining − walletPart)` (شحّ المحفظة).
- **تحقق المكمّل (secondary):** إن أرسل العميل `secondary.amount` ويختلف عن `rest` بمقدار ≥ 0.01 → 422 «تغيّر رصيد المحفظة أثناء الدفع — أعد العملية» (البطاقة أخذت مبلغاً يخالف ما ستقيّده الدفاتر).
- إن `rest > 0` ووُجد `secondary`: إنشاء سطر Payment بالطريقة المكمّلة (verifyMode/reference للبطاقة/التحويل)؛ يُعتبر الطلب مدفوعاً بالكامل (`paid = remaining`).
- إن لم يوجد مكمّل: يبقى `paid = walletPart` والطلب PARTIAL.
- تحديث `paidTotal += paid`؛ `paymentStatus = (paid ≥ remaining ? PAID : PARTIAL)`.

### 5.8 دفع لاحق عبر OrderDetail (`storePayment`)
`POST /orders/{id}/payments` — تسجيل دفعة على طلب قائم. طرق مسموحة: `CASH, CARD, TRANSFER, WALLET, DEFERRED` (لا SUBSCRIPTION).
- يرفض إن كان الطلب `CANCELLED` (422 «الطلب ملغي»).
- `remaining = round2(grandTotal − paidTotal)`.
- **WALLET يتطلب نفس تحقق OTP الذرّي** كـ POS (`kind='pos-otp'`, customerId مطابق لـ `order->customerId`, orgId مطابق, `TokenDenylist::reserve`)، وإلا يصبح هذا المسار تحايلاً على الموافقة (أنشئ غير مدفوع ثم سوِّ هنا).
- **DEFERRED:** لا Payment ولا قيد؛ فقط `paymentStatus = (remaining ≤ 0 ? PAID : DEFERRED)` ثم مزامنة الأرشفة.
- إن `remaining ≤ 0` (فحص مسبق غير مقفول) → 422 «الطلب مسدَّد بالكامل».
- **المعاملة المقفولة:** إعادة قراءة الطلب بـ `lockForUpdate` وإعادة حساب remaining من القيمة المقفولة؛ إن `≤ 0` → 422. ثم `amount = min(round2(data.amount), remaining)` (لا تحصيل زائد).
  - WALLET: جلب العميل ضمن المنشأة (422 إن غاب) وخصم عبر `WalletService::debit`.
  - إنشاء سطر Payment؛ `paidTotal += amount`؛ `paymentStatus = (paidTotal ≥ grandTotal ? PAID : PARTIAL)`.
- بعد المعاملة: مزامنة الأرشفة + `AccountingCore::syncOrderAccounting` (ينشر Dr نقد/بنك / Cr AR).

---

## 6. تحقق OTP للعميل الحاضر (request/verify/الاستهلاك/reserve)

`PosOtpController`. قبل خصم المحفظة أو استهلاك الاشتراك، يثبت العميل حضوره برمز يُرسل لهاتفه. تخزين `OtpCode` بنفس قواعد دخول البوابة (bcrypt hash، صلاحية 5 دقائق، استعمال واحد، سقف 5 محاولات، مربوط بـ orgId+phone).

الثوابت: `OTP_TTL_SECONDS = 300`, `OTP_PURPOSE = 'POS_WALLET'` (فصل حاسم عن أكواد دخول البوابة — لولاه لكان الكود الذي يُلقيه العميل صالحاً لجلسة بوابة 30 يوماً على تاريخ طلباته)، `MAX_ATTEMPTS = 5`, `PROOF_TTL_SECONDS = 600` (توكن الإثبات لعملية دفع واحدة).

### 6.1 `POST /pos/otp/request {customerId}` (throttle: otp-request)
1. جلب العميل المملوك للمنشأة (404 إن لم يوجد)؛ 422 «لا يوجد رقم هاتف للعميل» إن فارغ.
2. تحديد وجود قناة حقيقية: `hasProvider = MessagingProvider::canDeliver() || senderMode(org)==='CUSTOM'`؛ `isLocal = بيئة local/testing`.
3. **fail-closed:** إن `!hasProvider && !isLocal` → 422 «خدمة رمز التحقق غير مُهيأة» (كود تجريبي قابل للتخمين خارج local = تجاوز موافقة العميل).
4. الكود: في local/testing هو `demoCode()` (env `DEMO_OTP` بـ 4–8 أرقام أو `"0000"`)؛ خارجها `random_int(0..999999)` بست خانات.
5. إنشاء `OtpCode` (purpose=POS_WALLET، orgId، phone، codeHash=bcrypt، expiresAt=+5د، attempts=0).
6. إن وُجد provider: إرسال عبر بوابة `WaService` المركزية (حصة + مفاتيح + مُرسِل المنشأة + سجلّ) برندرة قالب OTP؛ best-effort لا يرمي.
7. الاستجابة `{sent:true, delivered, phone}`؛ في local فقط تُضاف `devCode` (لا تُكشف خارج local/testing إطلاقاً).

### 6.2 `POST /pos/otp/verify {customerId, code}` (throttle: otp-verify)
1. جلب العميل المملوك للمنشأة.
2. أحدث `OtpCode` بـ (orgId, purpose=POS_WALLET, phone, consumedAt فارغ) مرتّباً تنازلياً بـ createdAt.
3. الرفض بـ 422: لا كود → «رمز غير صحيح»؛ منتهٍ → «انتهت صلاحية الرمز»؛ `attempts ≥ 5` → «تم تجاوز عدد المحاولات».
4. إن فشل `password_verify(code, codeHash)` → `increment('attempts')` و422 «رمز غير صحيح».
5. **استهلاك ذرّي:** `UPDATE ... SET consumedAt=now() WHERE id=... AND consumedAt IS NULL`؛ إن رجع 0 → 422 (كود واحد لا يمكن أن يصكّ توكنين متزامنين).
6. **إصدار توكن الإثبات:** `ApiToken::issue({kind:'pos-otp', customerId, orgId}, 600s)`. الاستجابة `{verified:true, otpToken}`.

### 6.3 نمط الاستهلاك و`reserve` الذرّي
توكن الإثبات مربوط بـ customerId+orgId ومحدود بعمر 10 دقائق. عند الدفع (§4.5 أو §5.8) يُحرَق jti التوكن عبر `TokenDenylist::reserve($claims)` — قفل استشاري ذرّي check-and-set يحرق الـ jti **قبل** أي تحرّك للمال، فلا يمكن لعمليتي دفع متزامنتين بنفس التوكن أن تنجحا معاً (منع سباق إعادة اللعب → خصم مزدوج). هذا هو النمط المعياري §4.1 في الملف 00.

---

## 7. دورة حياة الطلب والأتمتة

### 7.1 آلة الحالات الكاملة (`ORDER_FLOW`)
مُعرَّفة متطابقةً في `OrderDetailController`, `AutomationSweeper`, `BoardController` (تحاكي `src/lib/order-flow.ts`):

| من | إلى المسموح |
|---|---|
| `RECEIVED` | `PROCESSING`, `CANCELLED` |
| `PROCESSING` | `READY`, `CANCELLED` |
| `READY` | `DELIVERED` |
| `DELIVERED` | (نهائية — لا انتقال) |
| `CANCELLED` | (نهائية — لا انتقال) |

ملاحظات: لا يمكن الإلغاء بعد READY (فقط DELIVERED منها). DELIVERED و CANCELLED حالتان نهائيتان.

### 7.2 تغيير الحالة يدوياً (`PATCH /orders/{id}/status`)
1. جلب الطلب المملوك للمنشأة (ضمن `branchIds`، 404 إن لا).
2. التحقق أن `status` قيمة صالحة، وأن الانتقال مسموح في `ORDER_FLOW[order->status]` وإلا 422 «انتقال غير مسموح به».
3. ختم الحالة الجديدة؛ إن DELIVERED → `deliveredAt = now()`. حفظ.
4. `AuditTrail::log(userId, 'STATUS', 'Order', id, {from}, {to, orderNo}, ip)`.
5. `syncArchive($order)` (§7.4).
6. إن `CANCELLED` → عكس محاسبي + استرداد محفظة + استعادة اشتراك (§8).
7. إشعار واتساب حسب الحالة: `READY`→`orderReady`، `DELIVERED`→`orderCompleted` (best-effort).

### 7.3 الأتمتة (`AutomationSweeper::sweep`)
تمريرة واحدة على كل منشأة فعّلت الأتمتة، تقدّم الطلبات المتجاوزة عتبة عمرها نحو READY (RECEIVED→PROCESSING→READY) وتكتب صف `OrderStatusHistory` لكل قفزة. يشترك فيها مسار HTTP (`CronController`) والأمر المجدول (`automation:sweep`).

الإعداد يُقرأ من `Setting` بمفتاح `automation.config:{orgId}` (branchId فارغ). الافتراضي (`DEFAULT_AUTOMATION`):
```
enabled: false
default: { normal: 180, express: 30 }   // دقائق
byServiceType: { WASH:{n:0,e:0}, IRON:{n:0,e:0}, WASH_IRON:{n:0,e:0} }
```

الخطوات لكل منشأة مفعّلة:
1. جلب فروعها؛ تخطٍّ إن لا فروع.
2. المرشّحون: طلبات الفروع بحالة `RECEIVED` أو `PROCESSING`، مرتّبة `createdAt` تصاعدياً، حد 500، مع أنواع خدمات البنود (`service.serviceType`).
3. لكل طلب: حساب `delay = resolveDelayMinutes(cfg, priority, types)`؛ إن `≤ 0` تخطٍّ.
4. `dueAt = createdAt + delay×60 ثانية`؛ إن `now < dueAt` تخطٍّ.
5. وإلا `advanceToReady($order)`.

**عتبة التأخير لكل نوع خدمة (`resolveDelayMinutes`):**
- `speed = (priority==='EXPRESS' ? 'express' : 'normal')`.
- الأنواع = أنواع خدمات الطلب، أو `['WASH']` إن فارغة.
- لكل نوع: القاعدة الخاصة `cfg.byServiceType[type][speed]` إن كانت > 0، وإلا الافتراضي `cfg.default[speed]`.
- `delay = max(delay, perType)` عبر كل الأنواع (**الأبطأ يحكم**: خدمة متعددة الأنواع تنتظر أطول عتبة).

**التقديم (`advanceToReady`):**
- الخطوات: من RECEIVED → `[PROCESSING, READY]`؛ من PROCESSING → `[READY]`.
- لكل قفزة: التحقق من صحة الانتقال (`RuntimeException('INVALID_TRANSITION')` إن غير صالح)، ثم `DB::transaction`: حفظ الحالة + إنشاء `OrderStatusHistory` (userId=null، note=«أتمتة (تحويل تلقائي)»، at=now).
- عند الوصول READY → `WaService::trigger('orderReady', $order)` (best-effort).
- الأخطاء لكل طلب تُلتقط وتُبلَّغ (`report`) دون إيقاف التمريرة؛ العدّادات المُعادة `{orgs, scanned, advanced}`.

### 7.4 الأرشفة (`syncArchive`)
- `shouldArchive = (status==='DELIVERED' && paymentStatus==='PAID')`.
- إن يجب الأرشفة ولا `archivedAt` → ختم `archivedAt = now()`.
- إن لا يجب و`archivedAt` مختوم → تصفيره (إعادة فتح — مثلاً استرداد جعل الطلب غير مدفوع).
- تُستدعى بعد تغيير الحالة وبعد كل دفعة (طلب DELIVERED صار PAID يُؤرشف تلقائياً).

---

## 8. الإلغاء والاسترداد

عند الانتقال إلى `CANCELLED` (عبر `PATCH .../status`)، تُنفَّذ ثلاث عمليات عكس، كلها idempotent بأقفال، وكلها best-effort (لا تُوقف تغيير الحالة نفسه — الأخطاء تُبلَّغ):

### 8.1 عكس قيد البيع
`AccountingCore::syncOrderAccounting($order->id)` — يعيد إخراج الإيراد + ضريبة المخرجات (إشعار دائن)، idempotent. التفاصيل في الملف 08.

### 8.2 استرداد المحفظة (`refundWalletOnCancel`)
1. `walletPaid = round2(Σ Payment.amount حيث method=WALLET لهذا الطلب)`؛ إن `≤ 0` خروج.
2. جلب العميل و orgId (من الفرع أو العميل)؛ خروج إن غابا.
3. `DB::transaction` مع **قفل استشاري** `pg_advisory_xact_lock(hashtext('walletrefund:'+id))` — يُسلسل الإلغاءات المتزامنة لهذا الطلب قبل قرار «هل استُرد؟» (نقرة مزدوجة كانت تُغني الاثنين معاً فيُدفع العميل مرتين).
4. فحص idempotency: وجود قيد `(source=ORDER, refType=OrderWalletRefund, refId=order)` → خروج (استُرد).
5. `WalletService::credit(customer, walletPaid, 'REFUND', ..., 'WALLET', postAccounting:false)` — استرداد يعيد الالتزام المؤجّل لا شحن نقدي، فالقيد يُكتب يدوياً.
6. قيد `(source=ORDER, refType=OrderWalletRefund, refId=order)`: Dr `AR` / Cr `DEFERRED_REVENUE` بقيمة walletPaid.
> استرداد النقد/البطاقة يبقى عملية درج يدوية.

### 8.3 استعادة حصة الاشتراك (`restoreSubscriptionOnCancel`)
1. إن لا `subscriptionId` → خروج (لم يُسدَّد من اشتراك).
2. `orgId` من الفرع؛ خروج إن غاب.
3. `DB::transaction` مع قفل استشاري `pg_advisory_xact_lock(hashtext('subrestore:'+id))`.
4. idempotency: وجود قيد `(refType=SubscriptionRestore, refId=order)` → خروج.
5. جلب الاشتراك مقفولاً (`lockForUpdate`)؛ خروج إن غاب أو بلا خطة.
6. **إعادة ما أُخذ بالضبط:**
   - `PIECE_QUOTA`: `pieces = Σ quantity`؛ إن `≤ 0` خروج؛ `remainingQuota += pieces`؛ `restoredValue = grandTotal`.
   - `PREPAID_BALANCE`: `restoredValue = grandTotal`؛ إن `≤ 0` خروج؛ `remainingBalance += restoredValue`.
   - `UNLIMITED_SERVICE` وغيره: خروج (لم يُستهلك عدّاد).
7. إن `restoredValue > 0`: قيد `(source=PAYMENT, refType=SubscriptionRestore, refId=order)`: Dr `AR` / Cr `DEFERRED_REVENUE` (عكس قيد الاستهلاك).

---

## 9. اللوحة (board/scan) وإشعار الطلب وحاسبة الجدوى

### 9.1 اللوحة (`BoardController`, للقراءة فقط)
تقديم الحالة يعيد استخدام `PATCH /orders/{id}/status`.

**`GET /board/orders`** — سلال نشطة مجمّعة بالحالة (kanban). أعمدة `BOARD_STATUSES = [RECEIVED, PROCESSING, READY, DELIVERED]`:
- النشطة: طلبات `branchIds` بحالة (RECEIVED/PROCESSING/READY) و`archivedAt` فارغ، ترتيب createdAt تنازلي، حد 300.
- المُسلَّم اليوم: `status=DELIVERED` و`deliveredAt ≥ بداية اليوم`، ترتيب deliveredAt تنازلي، حد 100.
- الاستجابة `{columns, total}`؛ كل بطاقة عبر `cardPayload`.

**`GET /board/scan/{barcode}`** — بحث بالباركود ضمن `branchIds` (404 «لم يُعثر على السلة» إن مجهول/أجنبي). يعيد `cardPayload` مع `nextStatuses` ليقدّم المسح الطلب (التقديم عبر PATCH).

`cardPayload`: id, orderNo, barcode, status, paymentStatus, nextStatuses (من ORDER_FLOW)، grandTotal, paidTotal، العميل (id/name/phone)، dueAt, deliveredAt, createdAt.

### 9.2 إشعار الطلب (`OrderNotifyController`)
**`POST /orders/{id}/notify {channel: WHATSAPP|SMS}`** — يؤلّف رسالة فاتورة/حالة عربية ويرسلها لعميل الطلب. أي موظّف (مدير أو كاشير) — الإرسال عمل واجهة روتيني، فالبوابة على النطاق/الموظّف فقط (`branchIds` يرفض غير الموظّف وعبر المنشآت).
- جلب الطلب ضمن `branchIds` مع العميل (404)؛ 422 إن لا عميل، 422 إن لا هاتف.
- تأليف الرسالة (`composeMessage`): اسم البائع (org->name أو «مغسلتنا»)، التحية، «فاتورة طلبكم رقم {orderNo}»، «الحالة: {عربي}» (خريطة `STATUS_AR`)، «الإجمالي: X ﷼ (شامل ضريبة القيمة المضافة)»، رابط المتابعة، الشكر.
- الرابط (`orderLink`): `{WEB_URL}/portal/{slug|id}/orders/{orderId}` (أو `/orders/{id}` إن لا handle).
- الإرسال عبر بوابة `WaService::queue` (حصة + مفاتيح + مُرسِل + سجلّ)؛ `sent = الحالة ليست FAILED/BLOCKED`.
- تسجيل محاولة `Notification` (channel, template='invoice', body, status=SENT/FAILED, refId=order, sentAt). الاستجابة `{sent, channel}`.

### 9.3 حاسبة الجدوى (`FeasibilityController`)
**`POST /feasibility`** — أداة تسويقية **عامة، بلا مصادقة، بلا قاعدة بيانات** (حساب صرف). ثوابت: `CONTINGENCY_RATE = 0.06`, `DEFAULT_WORKING_DAYS = 26`.

المدخلات: `ordersPerDay*, avgTicket*` (مطلوبة)؛ `workingDays` (1–31، افتراضي 26)، `rent, staffCount, salaryPerStaff, utilities, consumablesPerOrder, otherMonthly, investment` (اختيارية ≥ 0).

الحساب:
- **الحجم والإيراد:** `monthlyOrders = ordersPerDay × workingDays`؛ `monthlyRevenue = monthlyOrders × avgTicket`.
- **التكاليف:** `staffCost = staffCount × salaryPerStaff`؛ `fixedCosts = rent + staffCost + utilities + otherMonthly`؛ `variableCosts = monthlyOrders × consumablesPerOrder`؛ `contingency = round((fixedCosts + variableCosts) × 0.06, 2)` (احتياطي 6% على القاعدة كلها)؛ `totalCosts = fixedCosts + variableCosts + contingency`.
- **الربح:** `netProfit = monthlyRevenue − totalCosts`؛ `profitMargin = (netProfit / monthlyRevenue) × 100` (0 إن الإيراد صفر).
- **نقطة التعادل (هامش المساهمة، متّسقة مع الاحتياطي):** `contributionPerOrder = avgTicket − consumablesPerOrder × 1.06`؛ `fixedWithReserve = fixedCosts × 1.06`؛ `breakevenOrdersMonth = ceil(fixedWithReserve / contributionPerOrder)` إن المساهمة > 0 وإلا null؛ `breakevenOrdersDay = ceil(month / workingDays)`.
- **استرداد الاستثمار:** `paybackMonths = ceil(investment / netProfit)` إن investment>0 و netProfit>0، وإلا null.

المخرجات: `inputs`, `monthly` (orders, revenue, staffCost, fixedCosts, variableCosts, contingency, totalCosts, netProfit, profitMargin)، `annual` (شهري×12)، `breakeven` (contributionPerOrder, ordersPerMonth, ordersPerDay, paybackMonths).

---

## 10. قواعد البيزنس (مرقّمة)

1. **الإجماليات تُحسب على الخادم حصراً؛** سعر العميل لكل بند يُهمَل ويُعاد حسابه من الكتالوج (`basePrice + expressSurcharge إن استعجالي ومتاح`).
2. **الضريبة تُحسب على الصافي بعد الخصم** (`taxableBase`), لا على الإجمالي الفرعي.
3. **الخصم لا يتجاوز الإجمالي الفرعي أبداً** (حدّ مزدوج: PERCENT محصور 0–100، وحدّ نهائي `min(discount, subtotal)`) — منعاً لإجمالي/ضريبة سالبة.
4. **رسم الاستعجال نوعان:** على مستوى السلة (`expressSurcharge` يُضاف لـ subtotal) وعلى مستوى السطر (`isExpress` يرفع `unitPrice`)؛ الثاني يتطلب `svc->isExpressAvailable`.
5. **idempotency عبر `clientRequestId`** بحاجزين: فحص مسبق قبل الإدراج، وفهرس فريد `(branchId, clientRequestId)` يلتقط السباق المتزامن ويعيد الطلب الفائز بـ 200.
6. **كل الكيانات المرجعية تُنطّق للمنشأة:** الفرع، العميل، كل خدمة — 404 لأي أجنبي (منع كتابة مال/تسعير عبر المنشآت).
7. **CARD/TRANSFER يتطلب verifyMode** (MANUAL/TERMINAL)، و**TERMINAL يتطلب reference** (رمز موافقة الشبكة) وإلا يُرفض الطلب كلّه.
8. **WALLET/SUBSCRIPTION يتطلب توكن إثبات OTP** صالحاً مربوطاً بالعميل+المنشأة، **يُحرَق ذرّياً بـ `TokenDenylist::reserve` قبل تحرّك المال** (منع خصم مزدوج).
9. **كل حركات محفظة العميل تمرّ بـ `WalletService`** (قراءة مقفولة FOR UPDATE) — لا قراءة-وكتابة يدوية للرصيد.
10. **الدفع بالمحفظة/الاشتراك المدفوع لا يُنشئ قيد بيع** (القيمة مسجّلة سلفاً كإيراد مؤجّل)؛ استهلاكها يقيّد Dr إيراد مؤجّل / Cr ذمم مدينة.
11. **الدفع بالاشتراك لا يُنشئ سطر Payment** (لا قيمة enum لـ SUBSCRIPTION)؛ يُميَّز بـ `refType='SubscriptionConsume'`.
12. **فائض النقد:** إلى CHANGE (بلا قيد) أو WALLET (شحن Dr نقد / Cr إيراد مؤجّل).
13. **شحّ المحفظة:** يُكمَّل بطريقة secondary (CASH/CARD/TRANSFER)؛ إن أرسل العميل مبلغ المكمّل ويختلف عن المتبقّي بـ ≥ 0.01 يُرفض الطلب.
14. **`taxRate` يُلتقط كلقطة** لحظة الإنشاء (امتثال ZATCA)، لا يُقرأ لاحقاً.
15. **آلة الحالات صارمة أحادية الاتجاه:** لا إلغاء بعد READY؛ DELIVERED/CANCELLED نهائيتان.
16. **`deliveredAt` يُختم عند التسليم** (إحصاءات «سُلّم اليوم» واللوحة تعتمد عليه لا على updatedAt).
17. **الأرشفة عند DELIVERED و PAID معاً**؛ تُعاد الفتح إن انتفى شرط (استرداد يعيد الطلب غير مدفوع).
18. **الأتمتة تُقدّم فقط RECEIVED/PROCESSING → READY** بعد تجاوز عتبة العمر؛ الأبطأ من أنواع الخدمات يحكم؛ لا تُلغي ولا تُسلّم.
19. **الإلغاء يعكس كل شيء idempotently بأقفال:** قيد البيع + استرداد المحفظة (قفل استشاري + فحص قيد) + استعادة حصة/رصيد الاشتراك (قفل استشاري + فحص قيد).
20. **استعادة الاشتراك مشروطة بـ `subscriptionId` المملوء** لحظة الدفع؛ بدونه يخسر العميل حصّته المستهلكة.
21. **الدفع اللاحق يعيد القراءة تحت قفل صف** ويحصر المبلغ بالمتبقّي (لا تحصيل زائد، لا فقدان كتابة متزامنة).
22. **العمليات الجانبية best-effort** (المحاسبة، واتساب) تُلتقط وتُبلَّغ ولا توقف البيع/تغيير الحالة؛ أمر backfill يعيد نشر المحاسبة المفقودة.
23. **أكواد OTP لنقطة البيع معزولة** بـ `purpose='POS_WALLET'` عن أكواد دخول البوابة (منع مقايضة تأكيد درج بجلسة بوابة).
24. **fail-closed لـ OTP خارج local** إن لا قناة رسائل حقيقية (منع كود تجريبي قابل للتخمين).

---

## 11. الأدوار والصلاحيات لكل عملية

| العملية | البوابة |
|---|---|
| `POST /pos/orders` (إنشاء) | `requirePermission('pos.checkout')` + `requireActiveSubscription` (402 إن غير نشط) + `assertStaff` ضمناً عبر orgId |
| `POST /pos/otp/request` | موظّف (assertStaff عبر orgId)؛ throttle: otp-request |
| `POST /pos/otp/verify` | موظّف؛ throttle: otp-verify |
| `GET /orders/{id}` | موظّف، ضمن `branchIds` (404 إن غير مملوك) |
| `PATCH /orders/{id}/status` | موظّف، ضمن `branchIds` (لا بوابة دور إضافية) |
| `POST /orders/{id}/payments` | موظّف، ضمن `branchIds`؛ WALLET يتطلب توكن OTP |
| `POST /orders/{id}/notify` | أي موظّف (مدير أو كاشير)، ضمن `branchIds` |
| `GET /board/orders`, `GET /board/scan/{barcode}` | موظّف، ضمن `branchIds` |
| `POST /feasibility` | **عام — بلا مصادقة** |

`assertStaff` يرفض توكن العميل/المورّد (kind != 'staff' أو userId فارغ → 403). `branchIds` يوسّع للقراءة عبر فروع المنشأة، ويضيق بترويسة `X-Branch-Id` إن سمّت فرعاً داخل المنشأة (لا يوسّع أبداً، ولا يؤثر على فرع الكتابة `branchId()`).

> ملاحظة صلاحية: تغيير الحالة `PATCH .../status` لا يفرض بوابة دور إضافية (أي موظّف ضمن المنشأة يقدّم/يلغي). صلاحية دقيقة مفروضة صراحةً على الإنشاء فقط (`pos.checkout`).

---

## 12. قائمة العمليات الكاملة

| الطريقة والمسار | المتحكّم/الخدمة | الوصف |
|---|---|---|
| `POST /pos/orders` | `PosController::store` | إنشاء طلب من خلايا الكتالوج + دفع اختياري عند الخروج |
| `POST /pos/otp/request` | `PosOtpController::request` | إرسال كود تحقق لهاتف العميل |
| `POST /pos/otp/verify` | `PosOtpController::verify` | التحقق وإصدار توكن إثبات pos-otp لعملية واحدة |
| `GET /orders/{id}` | `OrderDetailController::show` | تفاصيل الطلب الكاملة (رأس، بنود، دفعات، عميل، إحصاءات، نشاط) |
| `PATCH /orders/{id}/status` | `OrderDetailController::updateStatus` | تقديم مرحلة سير العمل + عكوس الإلغاء + إشعارات |
| `POST /orders/{id}/payments` | `OrderDetailController::storePayment` | تسجيل دفعة على طلب قائم |
| `POST /orders/{id}/notify` | `OrderNotifyController::notify` | إرسال فاتورة/حالة عبر واتساب/SMS + سجلّ Notification |
| `GET /board/orders` | `BoardController::orders` | سلال نشطة مجمّعة بالحالة (kanban) |
| `GET /board/scan/{barcode}` | `BoardController::scan` | بحث سلة بالباركود لمحطة المسح |
| `POST /feasibility` | `FeasibilityController::calculate` | حاسبة جدوى عامة (حساب صرف) |
| (خدمة داخلية) | `OrderCreator::create` | إنشاء طلب مُسعَّر لجرد سلة التوصيل (فاتورة) |
| (خدمة/أمر) | `AutomationSweeper::sweep` | تمريرة أتمتة تقدّم الطلبات نحو READY |

---

## 13. حالات خاصة وفجوات معروفة

1. **الطلب الصفري:** إجمالي 0 بلا دفع → PAID مباشرة (`remaining ≤ 0`).
2. **إعادة المزامنة offline:** استجابة ضائعة بعد التزام الخادم تُعالَج بـ `clientRequestId` (حاجزان)؛ بدونه يُعاد الإنشاء (فوترة مزدوجة).
3. **تصادم orderNo/barcode:** حتى 6 محاولات برفع التسلسل (attempt)؛ الفشل بعدها → 409.
4. **`OrderCreator` لا يطبّق خصماً ولا `expressSurcharge` سلة** (جرد التوصيل) — نموذج تسعير مبسّط، ولا idempotency عبر clientRequestId.
5. **شحّ المحفظة بلا secondary:** الطلب يبقى PARTIAL (لا يُرفض).
6. **تغيّر رصيد المحفظة أثناء الدفع:** يُكشف بمطابقة `secondary.amount` مع المتبقّي المحسوب من الرصيد المقفول (≥ 0.01 فرق → 422).
7. **الدفع بالاشتراك لا يمرّ عبر `storePayment`** (طرقه لا تشمل SUBSCRIPTION) — فقط عند إنشاء POS.
8. **استعادة الاشتراك تعتمد على `subscriptionId`؛** طلبات قديمة سُدّدت قبل ملء هذا العمود لن تستعيد حصّتها عند الإلغاء (فجوة تاريخية أُغلقت بملء العمود لاحقاً).
9. **استرداد النقد/البطاقة يدوي** — الإلغاء يعكس المحاسبة والمحفظة والاشتراك آلياً فقط.
10. **الأتمتة لا تُسلّم ولا تُلغي؛** تتوقف عند READY. التسليم يدوي دائماً.
11. **حدّ 500 مرشّح لكل منشأة في تمريرة الأتمتة، و300/100 في اللوحة** — سقوف صلبة قد تُخفي طلبات في المنشآت الكبيرة (مؤجّل الترقيم/الـ pagination — راجع audit).
12. **DEFERRED لا يُنشئ قيداً** (وإلا لأخطأ `AccountingCore` بقيده لـ BANK) — مجرد علامة حالة.
13. **إشعار الطلب best-effort**؛ الفشل يُسجَّل كـ Notification بحالة FAILED دون رفع خطأ.
14. **حاسبة الجدوى عامة تماماً** — لا مصادقة، لا قاعدة بيانات، لا تنطيق منشأة؛ حساب صرف يُرجَع كما هو.
15. **`activity()` تدمج مصدرين** (AuditLog يدوي + OrderStatusHistory تلقائي) مع إزالة تكرار بمفتاح (الحالة الهدف|الثانية) — قد تُزيل انتقالين مشروعين وقعا في نفس الثانية لنفس الحالة الهدف (احتمال نادر جداً).
16. **الدفعات غير منسوبة لمستخدم** (`Payment` لا يحمل userId) — تظهر في النشاط بلا فاعل.
