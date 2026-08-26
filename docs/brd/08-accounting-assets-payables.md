# BRD 08 — المحاسبة والأصول والذمم الدائنة

> يفترض هذا المستند أنك قرأت `00-overview-architecture.md` (المصادقة بتوكن HMAC، عزل المستأجرين عبر `ResolvesTenant`، قاعدة البيانات المشتركة المملوكة لـ Prisma، تخزين JSON في جدول `Setting` عبر `OrgStore`).

هذا مستند استخراجي دقيق لوحدة المحاسبة بالقيد المزدوج ووحداتها المرتبطة (الأصول الثابتة، الميزانيات، المصروفات، الذمم الدائنة، إقفال الفترة). المرجع الكودي:

- المحرك: `app/Support/Accounting/AccountingCore.php`، `AccountingReports.php`، `AccountingException.php`
- قفل الفترة: `app/Support/BooksLock.php`
- دفاتر المنصّة (المشغّل SaaS): `app/Support/PlatformBooks.php`
- الخدمات: `app/Services/AssetsService.php`، `PayablesService.php`، `WalletService.php`
- المتحكمات: `app/Http/Controllers/Api/AccountingController.php`، `BudgetsController.php`، `AssetsController.php`، `PayablesController.php`
- النماذج: `app/Models/Account.php`، `JournalEntry.php`، `JournalLine.php`، `Expense.php`
- المسارات: `routes/api/accounting.php`، `budgets.php`، `assets.php`، `payables.php`
- أمر الترحيل الرجعي: `app/Console/Commands/AccountingBackfill.php`

---

## 1. نظرة عامة

النظام يمسك **دفتر أستاذ عام بالقيد المزدوج** لكل منشأة (org)، مطابق تماماً (port) لنظام Next.js الأصلي في `src/server/accounting/core.ts` و`reports.ts`. كل حركة مالية حقيقية (مبيعة، دفعة، إلغاء، شحن محفظة، اشتراك، مصروف، أصل) تُرحَّل كقيد يومية متوازن (Σمدين = Σدائن) عبر دالة واحدة `AccountingCore::postJournalEntry`.

مبادئ حاكمة:

1. **مصدر واحد للترحيل.** لا يُكتب أي `JournalEntry` إلا عبر `postJournalEntry` — هي التي تتحقق من التوازن وتُسند رقم القيد `entryNo` وتضمن عدم التكرار (idempotency).
2. **القيود النظامية غير قابلة للتعديل أو الحذف.** كل قيد `source != MANUAL` لا يُعدَّل ولا يُحذف عبر الـ API. التصحيح الوحيد المتاح هو **قيد عكسي** (تسوية عكسية) — صورة معكوسة لأسطر القيد الأصلي.
3. **التاريخ = تاريخ المستند.** القيد يُؤرَّخ بتاريخ الحدث المصدر (تاريخ السلة، تاريخ الدفعة، تاريخ المصروف)، لا بلحظة الترحيل. هذا يضمن أن الترحيل الرجعي للطلبات التاريخية يقع في الفترة المحاسبية الصحيحة (انحراف مقصود عن core.ts الذي كان يختم بلحظة الترحيل).
4. **الإيراد المؤجل (DEFERRED_REVENUE) والذمم المدينة (AR)** هما محورا التدفق: المبيعة تخلق ذمة مدينة (AR)، والدفعة تسدّدها، وشحن المحفظة يخلق التزام إيراد مؤجل يُستهلك لاحقاً.
5. **إضافي فقط على المخطط.** الميزانيات والأصول والذمم الدائنة تُخزَّن كـ JSON في جدول `Setting` عبر `OrgStore` (لا جداول جديدة)، بينما فواتير الموردين تُخزَّن كصفوف `Expense` عادية مع بيانات وصفية في `Setting`.

**دفاتر المنصّة**: المشغّل SaaS نفسه يمسك دفاتره على منشأة محجوزة (org مخصص) عبر نفس المحرك، فتعمل كل التقارير عليه بلا تغيير (انظر §7 من BRD المنصّة و§5.15 هنا).

---

## 2. الكيانات (الحقول والـ enums)

### 2.1 `Account` — حساب في دليل الحسابات (`app/Models/Account.php`، جدول `Account`)

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | string (cuid) | مفتاح أولي، غير تزايدي |
| `orgId` | string | المنشأة المالكة — كل استعلام مقيّد به |
| `code` | string (≤20) | رمز الحساب؛ فريد داخل `(orgId, code)` |
| `name` | string (2–120) | الاسم العربي للعرض |
| `nameEn` | string? (≤120) | الاسم الإنجليزي (اختياري) |
| `type` | enum | نوع الحساب: `ASSET` \| `LIABILITY` \| `EQUITY` \| `REVENUE` \| `EXPENSE` |
| `parentId` | string? | حساب أب (لبناء الشجرة الهرمية) |
| `isSystem` | boolean | حساب نظامي مُبذَّر تلقائياً — محمي من التعديل الجوهري والحذف |
| `systemKey` | string? | مفتاح النظام (CASH, AR, VAT_PAYABLE…) يربط الحساب بمنطق الترحيل التلقائي |
| `isActive` | boolean | مفعّل/معطّل |
| `createdAt` | datetime | (لا يوجد `updatedAt` — Prisma لا يعرّفه لهذا الجدول) |

- الرصيد لا يُخزَّن على الحساب؛ يُحسب دائماً من مجاميع أسطر القيود.

### 2.2 `JournalEntry` — قيد يومية (`app/Models/JournalEntry.php`، جدول `JournalEntry`)

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | string (cuid) | مفتاح أولي |
| `orgId` | string | المنشأة |
| `entryNo` | integer | رقم تسلسلي لكل منشأة = MAX+1؛ فريد على `(orgId, entryNo)` |
| `date` | datetime | تاريخ المستند المصدر (يحدد الفترة المحاسبية) |
| `memo` | string? | بيان القيد |
| `source` | enum `JournalSource` | مصدر القيد (انظر §2.5) |
| `refType` | string? | نوع المرجع (Order, Payment, Expense, Subscription…) |
| `refId` | string? | معرّف المستند المرجعي |
| `branchId` | string? | مركز التكلفة (الفرع) — اختياري |
| `createdById` | string? | المستخدم المُنشئ |
| `createdAt` | datetime | (لا `updatedAt`) |

- العلاقات: `lines()` (hasMany على `JournalLine.entryId`)، `branch()`.
- متوازن بالبناء — لا يُكتب إلا عبر `postJournalEntry`.

### 2.3 `JournalLine` — سطر مدين/دائن (`app/Models/JournalLine.php`، جدول `JournalLine`)

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | string (cuid) | |
| `entryId` | string | القيد الأب |
| `accountId` | string | الحساب المتأثر |
| `debit` | float | المبلغ المدين (≥0) |
| `credit` | float | المبلغ الدائن (≥0) |
| `branchId` | string? | يُنسخ من القيد |
| `memo` | string? | بيان السطر |

- **لا توجد أي طوابع زمنية** على هذا الجدول (`$timestamps = false`).
- في كل سطر إما `debit` أو `credit` موجب (لا الاثنان معاً بعد إسقاط الصفري).

### 2.4 `Expense` — مصروف (`app/Models/Expense.php`، جدول `Expense`)

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | string (cuid) | |
| `orgId` | string | المنشأة |
| `branchId` | string? | الفرع |
| `date` | datetime | تاريخ المصروف (يحدد الفترة) |
| `category` | enum | `OPEX` \| `PAYROLL` \| `RENT` \| `UTILITIES` \| `SUPPLIES` |
| `description` | string? | البيان |
| `amount` | float | الإجمالي (شامل الضريبة) |
| `vatAmount` | float | جزء ضريبة المدخلات القابل للخصم (≤ amount) |
| `accountId` | string | حساب المصروف من الدليل |
| `paidFrom` | string | مصدر السداد: `CASH` \| `BANK` \| `AP` |
| `reference` | string? (≤100) | مرجع خارجي |
| `journalEntryId` | string? | القيد المُرحَّل المرتبط |
| `createdById` | string? | المستخدم |
| `createdAt` | datetime | (لا `updatedAt`) |

- فاتورة المورد = صف `Expense` بـ `paidFrom='AP'` + بيانات payable في `Setting`.

### 2.5 enum `JournalSource` (Postgres enum — قِيَمه مغلقة)

القيم المسموحة **حصراً** (لا يمكن إضافة قيمة جديدة دون كسر تطبيق Next.js الحيّ):

```
MANUAL | ORDER | PAYMENT | REFUND | EXPENSE | WALLET_TOPUP | OPENING
```

> لا توجد قيمة `SUBSCRIPTION*`. لذلك تُميَّز التدفقات الجديدة عبر `refType` لا عبر `source`: تحصيل الاشتراك = `source='PAYMENT', refType='Subscription'`؛ استهلاك الاشتراك = `refType='SubscriptionConsume'`؛ استبدال الولاء = `source='MANUAL', refType='LoyaltyRedemption'`. (`AccountingController::JOURNAL_SOURCES` تعرّف نفس القائمة السبعة لفلترة عرض دفتر اليومية.)

### 2.6 enum `PaymentMethod` (Postgres enum — قِيَمه مغلقة)

```
CASH | CARD | TRANSFER | WALLET | DEFERRED
```

- لا قيمة `SUBSCRIPTION`. استهلاك الاشتراك لا يُنشئ صف `Payment` أصلاً (يُرحَّل قيداً مباشرةً).

### 2.7 الرصيد الطبيعي لكل نوع حساب

| النوع | الرصيد الطبيعي | حساب الرصيد (في `balancesByAccount`) |
|---|---|---|
| `ASSET` | مدين | `debit − credit` |
| `EXPENSE` | مدين | `debit − credit` |
| `LIABILITY` | دائن | `credit − debit` |
| `EQUITY` | دائن | `credit − debit` |
| `REVENUE` | دائن | `credit − debit` |

`debitNormal = (type === ASSET || type === EXPENSE)`.

---

## 3. شجرة الحسابات الكاملة

### 3.1 المخطط الافتراضي المُبذَّر لكل منشأة (`AccountingCore::DEFAULT_ACCOUNTS`)

يُبذَّر تلقائياً عبر `ensureChartOfAccounts` (idempotent — يتخطى الموجود، ويتجاهل تعارض التكرار 23505). كل هذه الحسابات `isSystem = true`.

| الرمز | الاسم العربي | الاسم الإنجليزي | النوع | systemKey |
|---|---|---|---|---|
| 1010 | الصندوق (نقدية) | Cash | ASSET | `CASH` |
| 1020 | البنك | Bank | ASSET | `BANK` |
| 1100 | العملاء (ذمم مدينة) | Accounts Receivable | ASSET | `AR` |
| 1200 | ضريبة القيمة المضافة على المشتريات (قابلة للخصم) | Input VAT (Recoverable) | ASSET | `INPUT_VAT` |
| 2010 | ضريبة القيمة المضافة على المبيعات (مستحقة) | Output VAT Payable | LIABILITY | `VAT_PAYABLE` |
| 2020 | إيراد مؤجل (محافظ العملاء) | Deferred Revenue (Wallets) | LIABILITY | `DEFERRED_REVENUE` |
| 2100 | الموردون (ذمم دائنة) | Accounts Payable | LIABILITY | `AP` |
| 3010 | رأس المال | Capital | EQUITY | `CAPITAL` |
| 3020 | الأرباح المحتجزة | Retained Earnings | EQUITY | `RETAINED` |
| 4010 | إيرادات خدمات الغسيل | Laundry Revenue | REVENUE | `SALES` |
| 4900 | خصومات المبيعات | Sales Discounts | REVENUE | `SALES_DISCOUNTS` |
| 5010 | مصروفات تشغيلية | Operating Expenses | EXPENSE | `OPEX` |
| 5020 | الرواتب والأجور | Salaries | EXPENSE | `PAYROLL` |
| 5030 | الإيجار | Rent | EXPENSE | `RENT` |
| 5040 | المرافق (كهرباء/ماء) | Utilities | EXPENSE | `UTILITIES` |
| 5050 | مستلزمات ومواد | Supplies | EXPENSE | `SUPPLIES` |

> ملاحظة: `SALES_DISCOUNTS` نوعه `REVENUE` لكنه يُستخدم كحساب **مقابل للإيراد** (contra-revenue): يُقيَّد مديناً ليخفّض صافي الإيراد. رصيده الطبيعي بحسبة النوع يكون `credit − debit`، فيظهر سالباً وينقص إجمالي الإيراد في قائمة الدخل.

### 3.2 حسابات الأصول الثابتة (تُنشأ كسولاً عبر `AssetsService::accounts`)

تُضاف عند أول عملية أصل للمنشأة (`isSystem = true`):

| الرمز | الاسم العربي | الإنجليزي | النوع | systemKey |
|---|---|---|---|---|
| 1500 | الأصول الثابتة (معدات وأجهزة) | Fixed Assets | ASSET | `FIXED_ASSET` |
| 1590 | مجمع الإهلاك | Accumulated Depreciation | ASSET | `ACCUM_DEP` (مقابل-أصل) |
| 5060 | مصروف الإهلاك | Depreciation Expense | EXPENSE | `DEP_EXPENSE` |
| 4950 | أرباح بيع أصول | Gain on Asset Disposal | REVENUE | `GAIN_DISPOSAL` |
| 5070 | خسائر بيع أصول | Loss on Asset Disposal | EXPENSE | `LOSS_DISPOSAL` |

### 3.3 حسابات دفاتر المنصّة فقط (`PlatformBooks`)

تُضاف على منشأة الدفاتر المحجوزة فقط (لا تمسّ المستأجرين):

| الرمز | الاسم | النوع | systemKey | ملاحظة |
|---|---|---|---|---|
| 4010 | إيرادات الاشتراكات (إعادة تسمية SALES) | REVENUE | `SALES` | يُعاد تسمية الحساب الأساسي لأن المشغّل يبيع اشتراكات لا غسيل |
| 4120 | مبيعات الأجهزة والمعدّات | REVENUE | `DEVICE_SALES` | إيراد بيع الأجهزة (POS…) منفصلاً |
| 3030 | توزيعات الشركاء (مسحوبات) | EQUITY | `PARTNER_DRAWINGS` | مقابل-حقوق ملكية للنقد المدفوع للشركاء المؤسّسين |

### 3.4 حسابات مخصّصة يضيفها المستخدم

عبر `POST /accounting/accounts` يمكن للمدير إضافة حساب **غير نظامي** (`isSystem=false`) بأي `type` من الخمسة، برمز فريد داخل المنشأة، واختيارياً تحت حساب أب مملوك للمنشأة.

---

## 4. قواعد القيد المزدوج (`postJournalEntry`)

الدالة `AccountingCore::postJournalEntry(array $input): JournalEntry`. المدخلات: `orgId` (إلزامي)، `source` (إلزامي)، `lines` (إلزامي)، واختيارياً `date, memo, refType, refId, branchId, createdById`.

الخطوات بالترتيب:

1. **إسقاط الأسطر الصفرية**: لكل سطر يُقرَّب `debit` و`credit` لخانتين عشريتين؛ إذا كان كلاهما `0.0` يُسقَط السطر (يطابق فلتر core.ts).
2. **حد أدنى سطران**: بعد الإسقاط، إن بقي أقل من سطرين تُرمى `AccountingException('ENTRY_NEEDS_TWO_LINES')`.
3. **التوازن بالهللات**: يُحسب `totalDebit` و`totalCredit` (مقرّبان). المقارنة تتم **بالأعداد الصحيحة من الهللات** `(int) round(total*100)` لتفادي أخطاء تمثيل العشور. إن اختلفا تُرمى `AccountingException("UNBALANCED_ENTRY: ...")`.
4. **التاريخ**: `date` المُمرَّر (كائن `DateTimeInterface` أو نص يُحلَّل بـ `Carbon::parse`)؛ وإن غاب فـ `now()`.
5. **عدم التكرار (Idempotency)** على `(orgId, source, refType, refId)`: داخل المعاملة، إن كان `refType` و`refId` كلاهما موجوداً ووُجد قيد سابق بنفس الرباعية، تُعاد النسخة الموجودة دون كتابة جديدة. (هذا يجعل إعادة الاستدعاء آمنة تماماً — أساس `syncOrderAccounting` والترحيل الرجعي.)
6. **رقم القيد `entryNo`** = `MAX(entryNo) + 1` لكل منشأة. لأن هذا قد يتصادم تحت التزامن (فريد على `(orgId, entryNo)` → خطأ 23505)، تُغلَّف العملية بحلقة **إعادة محاولة حتى 8 مرات** تعيد حساب الرقم عند تصادم 23505، فلا تُفقَد أي حركة بصمت.
7. **الكتابة داخل معاملة (`DB::transaction`)**: يُنشأ `JournalEntry` ثم أسطره `JournalLine` (مع نسخ `branchId` على كل سطر).

قواعد إضافية للترحيل تُطبَّق في طبقة المتحكم عند القيد اليدوي (`storeJournal`):

- كل الحسابات المستخدمة يجب أن تكون مملوكة للمنشأة (فحص `owned === count(ids)`).
- إجمالي المدين يجب أن يكون `> 0` (لا يُسمح بقيد صفري متوازن).
- الفرع (إن مُرِّر) يجب أن يكون مملوكاً للمنشأة.
- يخضع القيد اليدوي لقفل الفترة (`BooksLock::assertOpen`) لأن تاريخه يختاره المستخدم.

**التصحيح بالعكس فقط**: لا تعديل ولا حذف لأي قيد. `reverseJournal` يُنشئ قيداً جديداً `source='MANUAL', refType='JournalReversal', refId=<القيد الأصلي>` بأسطر معكوسة (debit↔credit). عدم التكرار مضمون: عكس نفس القيد مرتين يُعيد العكس الموجود مع علامة `alreadyReversed`.

---

## 5. قواعد الترحيل لكل حدث (جداول مدين/دائن)

المصطلحات: `grandTotal` = الإجمالي شامل الضريبة، `taxTotal` = الضريبة، `discountTotal` = الخصم، و**`grossSales = grandTotal − taxTotal + discountTotal`** (المبيعات الإجمالية قبل الخصم وبلا ضريبة). كل المبالغ مقرّبة لخانتين.

جدول موحّد لكل `(source, refType)`:

| الحدث | source | refType | refId |
|---|---|---|---|
| المبيعة | ORDER | Order | orderId |
| الإلغاء/الإشعار الدائن | ORDER | OrderReversal | orderId |
| استرجاع المحفظة عند الإلغاء | ORDER | OrderWalletRefund | orderId |
| دفعة الطلب (نقد/بطاقة/تحويل/محفظة) | PAYMENT | Payment | paymentId |
| تحصيل اشتراك (غير محفظة) | PAYMENT | Subscription | subscriptionId |
| استهلاك اشتراك | PAYMENT | SubscriptionConsume | orderId |
| استرجاع استهلاك اشتراك (إلغاء) | PAYMENT | SubscriptionRestore | orderId |
| شحن محفظة | WALLET_TOPUP | WalletTransaction | walletTxnId |
| استبدال نقاط الولاء | MANUAL | LoyaltyRedemption | loyaltyTxnId |
| مصروف / فاتورة مورد | EXPENSE | Expense | expenseId |
| عكس مصروف / إلغاء فاتورة | EXPENSE | ExpenseReversal | expenseId |
| سداد فاتورة مورد | PAYMENT | PayableSettlement | expenseId |
| اقتناء أصل | MANUAL | AssetAcquisition | assetId |
| إهلاك | MANUAL | Depreciation | `assetId:YYYY-MM` |
| استبعاد أصل | MANUAL | AssetDisposal | assetId |
| قيد يدوي | MANUAL | (فارغ) | — |
| قيد عكسي | MANUAL | JournalReversal | entryId |

### 5.1 المبيعة (Order / Order) — `syncOrderAccounting`

الشرط: القيد لم يُرحَّل بعد، الحالة `!= CANCELLED`، و`grandTotal > 0`.

| مدين | دائن |
|---|---|
| AR = `grandTotal` | SALES = `grossSales` |
| SALES_DISCOUNTS = `discountTotal` (إن > 0) | VAT_PAYABLE = `taxTotal` (إن > 0) |

البيان: `مبيعات سلة {orderNo}`، مؤرَّخ بـ `order.createdAt`.

### 5.2 الإلغاء / الإشعار الدائن (Order / OrderReversal)

الشرط: الحالة `CANCELLED`، والمبيعة الأصلية مُرحَّلة، والعكس لم يُرحَّل، و`grandTotal > 0`. عكس دقيق للمبيعة (معالجة الإشعار الدائن وفق ZATCA — يُردّ الإيراد والضريبة معاً):

| مدين | دائن |
|---|---|
| SALES = `grossSales` | AR = `grandTotal` |
| VAT_PAYABLE = `taxTotal` (إن > 0) | SALES_DISCOUNTS = `discountTotal` (إن > 0) |

### 5.3 دفعة الطلب (Payment / Payment) — سطر لكل صف دفعة

لكل دفعة `amount > 0` لم تُرحَّل بعد، يُحدَّد الحساب المدين حسب `method`:

- `CASH` → `CASH`
- `WALLET` → `DEFERRED_REVENUE` (استهلاك التزام مدفوع مسبقاً)
- `CARD` / `TRANSFER` → `BANK`

| مدين | دائن |
|---|---|
| CASH \| BANK \| DEFERRED_REVENUE = `amount` | AR = `amount` |

مؤرَّخ بـ `payment.createdAt`. (`DEFERRED` كطريقة دفع = بيع آجل: لا صف دفعة، تبقى AR مفتوحة.)

### 5.4 استرجاع المحفظة عند الإلغاء (Order / OrderWalletRefund)

عند إلغاء سلة دُفعت بالمحفظة: تُقفَل السلة بقفل استشاري `pg_advisory_xact_lock('walletrefund:'+orderId)`، ويُفحص عدم وجود استرجاع سابق، ثم يُعاد الرصيد للمحفظة عبر `WalletService::credit(..., postAccounting:false)` ويُرحَّل القيد التالي بمبلغ `walletPaid` (مجموع دفعات المحفظة على السلة):

| مدين | دائن |
|---|---|
| AR = `walletPaid` | DEFERRED_REVENUE = `walletPaid` |

المنطق: الاسترجاع يعيد الالتزام المؤجل، فهو ليس شحناً نقدياً.

### 5.5 شحن المحفظة (WALLET_TOPUP / WalletTransaction) — `postWalletTopUp`

النقد المستلم يُعامَل كالتزام إيراد مؤجل:

| مدين | دائن |
|---|---|
| CASH (إن method=CASH) وإلا BANK = `amount` | DEFERRED_REVENUE = `amount` |

- **مبدأ حاكم**: شحن المحفظة يُقيَّد مقابل DEFERRED_REVENUE **لا** Sales. لاحقاً عند الدفع بالمحفظة (§5.3) أو استهلاك الاشتراك (§5.7) يُقيَّد Dr DEFERRED_REVENUE / Cr AR، فيُنقل الالتزام لتسوية الذمة دون تكرار الاعتراف بالإيراد.

### 5.6 تحصيل الاشتراك — غير المحفظة (Payment / Subscription)

عند تحصيل قيمة اشتراك نقداً/تحويلاً (`SubscriptionController::pay` الفرع non-wallet):

| مدين | دائن |
|---|---|
| CASH (إن method=CASH) وإلا BANK = `amount` | DEFERRED_REVENUE = `amount` |

- إذا كان التحصيل **بالمحفظة**: يُخصَم رصيد المحفظة عبر `WalletService::debit` بعد تحقق OTP وحجز `TokenDenylist::reserve`، و**لا يُرحَّل قيد** في هذا الفرع (القيمة كانت مُعترَفاً بها كإيراد مؤجل عند الشحن). راجع الفجوة في §14.
- `subscriptionPaid()` يعتبر الاشتراك مدفوعاً إذا وُجد قيد `PAYMENT/Subscription` **أو** صف `WalletTransaction` من نوع DEBIT بـ `refId = sub.id`.

### 5.7 استهلاك الاشتراك (Payment / SubscriptionConsume) — `PosController`

عند تسوية سلة من رصيد اشتراك مدفوع (بعد خصم الكمية/الرصيد من الاشتراك):

| مدين | دائن |
|---|---|
| DEFERRED_REVENUE = `remaining` | AR = `remaining` |

- `remaining` = المتبقي غير المدفوع من السلة. لا يُنشأ صف `Payment` (لا قيمة SUBSCRIPTION في enum). idempotent على `orderId`.

### 5.8 استرجاع استهلاك الاشتراك عند الإلغاء (Payment / SubscriptionRestore) — `OrderDetailController`

عند إلغاء سلة سُوّيت من اشتراك: قفل استشاري `subrestore:orderId`، فحص عدم التكرار، ثم إعادة الكمية/الرصيد للاشتراك وترحيل عكس الاستهلاك بـ `restoredValue = grandTotal`:

| مدين | دائن |
|---|---|
| AR = `restoredValue` | DEFERRED_REVENUE = `restoredValue` |

- خطة PIECE_QUOTA: تُعاد القطع + يُعكَس بقيمة `grandTotal`. PREPAID_BALANCE: يُعاد `grandTotal` للرصيد ويُعكَس. UNLIMITED_SERVICE: لا شيء لاستعادته.

### 5.9 استبدال نقاط الولاء (MANUAL / LoyaltyRedemption) — `LoyaltyController::redeem`

تحويل نقاط ولاء إلى رصيد محفظة (`credit = points × program.pointValue`): يُضاف الرصيد عبر `credit(..., postAccounting:false)`، ويُرحَّل كخصم مبيعات:

| مدين | دائن |
|---|---|
| SALES_DISCOUNTS = `credit` | DEFERRED_REVENUE = `credit` |

- المنطق: المحفظة تحمل الآن قيمة لم تُقابَل بنقد؛ تحميلها كخصم مبيعات (مقابل-إيراد) يمنع انحراف DEFERRED_REVENUE للسالب ويُظهر كلفة برنامج الولاء في قائمة الدخل. idempotent على معرّف حركة الولاء.

### 5.10 المصروف (EXPENSE / Expense) — `storeExpense` / `PayablesService::postExpense`

بحساب `net = amount − vatAmount`، الحساب المدين هو حساب فئة المصروف (fallback إلى OPEX أو أي حساب مصروف)، والحساب الدائن حسب `paidFrom`:

| مدين | دائن |
|---|---|
| حساب الفئة (EXPENSE) = `net` (إن > 0) | CASH \| BANK \| AP = `amount` (gross) |
| INPUT_VAT = `vatAmount` (إن > 0) | |

- `paidFrom = CASH/BANK` → مصروف مدفوع مباشرةً. `paidFrom = AP` → فاتورة مورد مستحقة (دائن الذمم الدائنة).
- يُخزَّن `journalEntryId` على صف المصروف. البيان: `مصروف: {category} — {description}`.

### 5.11 حذف/عكس المصروف (EXPENSE / ExpenseReversal)

عند حذف مصروف أو إلغاء فاتورة مفتوحة: يُرحَّل قيد عكسي (صورة معكوسة لأسطر القيد الأصلي) **قبل** حذف الصف — فيبقى الأثر المحاسبي والدفتر يصفّر:

| مدين | دائن |
|---|---|
| كل حساب دائن في الأصل ← يصبح مديناً | كل حساب مدين في الأصل ← يصبح دائناً |

- لا يمكن حذف فاتورة **مدفوعة** (رسالة: أنشئ قيد تسوية بدلاً من ذلك).

### 5.12 سداد فاتورة المورد (PAYMENT / PayableSettlement) — `payBill`

| مدين | دائن |
|---|---|
| AP = `gross` | CASH (إن via=CASH) وإلا BANK = `gross` |

- يقلب حالة الفاتورة إلى `PAID` ويخزّن `paidJournalId`.

### 5.13 اقتناء الأصل (MANUAL / AssetAcquisition) — `AssetsService::create`

فقط إذا كان `paidFrom ∈ {CASH, BANK, AP}` (قيمة `NONE` تسجّل الأصل بلا قيد):

| مدين | دائن |
|---|---|
| FIXED_ASSET = `cost` | CASH \| BANK \| AP = `cost` |

### 5.14 الإهلاك (MANUAL / Depreciation, refId=`assetId:YYYY-MM`) — `depreciate`

القسط الثابت. `monthly = (cost − salvage) / usefulLifeMonths`. يُرحَّل مبلغ `amount = min(monthly × months, remaining)` حيث `months` = الأشهر الكاملة منذ آخر إهلاك:

| مدين | دائن |
|---|---|
| DEP_EXPENSE = `amount` | ACCUM_DEP = `amount` |

- **حاسم**: `refId = assetId:YYYY-MM` (مرتبط بالفترة). لو كان `assetId` فقط لأسقط حارس عدم التكرار كل شهر بعد الأول بصمت.

### 5.15 استبعاد الأصل (MANUAL / AssetDisposal) — `dispose`

يُلحَق الإهلاك حتى تاريخ الاستبعاد أولاً. ثم `bookValue = cost − accum`، و`gain = proceeds − bookValue`:

| مدين | دائن |
|---|---|
| CASH \| BANK = `proceeds` (إن > 0) | FIXED_ASSET = `cost` |
| ACCUM_DEP = `accum` (إن > 0) | GAIN_DISPOSAL = `gain` (إن ربح > 0) |
| LOSS_DISPOSAL = `−gain` (إن خسارة < 0) | |

- الربح/الخسارة يُقيَّد على حساب مخصّص، لا على SALES أو DEP_EXPENSE (حتى لا تنحرف مؤشرات الإيراد/المصروف التشغيلي).

### 5.16 قيود دفاتر المنصّة (`PlatformBooks`)

| الحدث | مدين | دائن |
|---|---|---|
| إيراد اشتراك (PAYMENT/SubscriptionInvoice) | BANK = gross | SALES = net، VAT_PAYABLE = vat |
| بيع جهاز (PAYMENT/DeviceSale) | BANK = gross | DEVICE_SALES = net، VAT_PAYABLE = vat |
| توزيع للشريك (MANUAL/PlatformPartnerDistribution) | PARTNER_DRAWINGS = amount | BANK = amount |
| مصروف مشغّل (EXPENSE/PlatformExpense) | OPEX = amount | CASH = amount |

- الضريبة محتسبة داخلياً: `vat = gross × 15 / 115`، `net = gross − vat`.

### 5.17 مفهوم DEFERRED_REVENUE و AR

- **AR (1100 — الذمم المدينة)**: تُنشأ عند كل مبيعة بالكامل `grandTotal`، وتُسدَّد بالدفعات (نقد/بنك/محفظة/استهلاك اشتراك). رصيدها المتبقّي = الفواتير غير المدفوعة (يُطابق تقرير أعمار الذمم).
- **DEFERRED_REVENUE (2020 — إيراد مؤجل، التزام)**: يتضخّم بشحن المحفظة وتحصيل الاشتراك واستبدال الولاء (قيمة استلمها العميل ولم تُستهلك بعد)، ويتقلّص عند استهلاكها (دفع بالمحفظة، استهلاك اشتراك) بتقييد Dr DEFERRED_REVENUE / Cr AR. هذا يمنع الاعتراف المزدوج بالإيراد: الإيراد يُعترَف مرة واحدة عبر حساب SALES في المبيعة نفسها.

---

## 6. التقارير المالية (`AccountingReports`)

كل تقرير يأخذ فلتراً `['orgId', 'from'?, 'to'?, 'branchId'?]`. `from/to` يقيّدان `JournalEntry.date` (شامل الطرفين). التمريرات التراكمية (الميزانية / الأرصدة الافتتاحية) **تتجاهل `from`** (كأن `cumulative=true`). `branchId` مرشّح مركز التكلفة.

الأساس المشترك `balancesByAccount(f, cumulative, includeZero)`: يجمع `SUM(debit), SUM(credit)` لكل حساب، ويحسب `balance` بإشارة الرصيد الطبيعي، ويُسقِط الحسابات عديمة النشاط ما لم يُطلب تضمينها، ويرتّب بالرمز.

### 6.1 ميزان المراجعة — `GET /accounting/trial-balance?from&to`
كل الحسابات مع مجاميع المدين/الدائن. يُرجِع `rows, totalDebit, totalCredit, balanced` (متوازن إذا تطابق الإجماليان بالهللات). يجب أن يصفّر.

### 6.2 قائمة الدخل — `GET /accounting/income-statement?from&to`
يُقسِّم الأرصدة إلى REVENUE وEXPENSE. يُرجِع `revenue[], expenses[], totalRevenue, totalExpenses, netIncome = totalRevenue − totalExpenses`. (SALES_DISCOUNTS وLoyaltyRedemption يقللان الإيراد لأنهما مقابل-إيراد.)

### 6.3 الميزانية العمومية — `GET /accounting/balance-sheet?asOf&branchId`
تراكمية حتى `asOf` (نهاية اليوم؛ افتراضياً now). يُرجِع `assets[], liabilities[], equity[], totalAssets, totalLiabilities, equityBase` وصافي الدخل للفترة `netIncome = revenue − expenses` و`totalEquity = equityBase + netIncome`. `balanced` = `totalAssets == totalLiabilities + totalEquity` (بالهللات). أي أن حقوق الملكية تشمل صافي دخل الفترة الجاري.

### 6.4 تقرير ضريبة القيمة المضافة المبسّط — `vatReport` (داخلي)
`outputVat` = (دائن − مدين) لحساب VAT_PAYABLE، `netSales` = مجموع أرصدة الإيراد، `rate = 15`.

### 6.5 إقرار ZATCA للضريبة — `GET /accounting/vat-return?from&to`
- `outputVat` = credit − debit لحساب VAT_PAYABLE (الإشعارات الدائنة/العكوس تُخصم).
- `inputVat` = debit − credit لحساب INPUT_VAT (ضريبة المدخلات القابلة للخصم).
- `standardRatedSales = outputVat / 0.15`، `standardRatedPurchases = inputVat / 0.15`.
- `netVat = outputVat − inputVat`؛ `netVatDue` = الموجب، `netVatRefundable` = |السالب|.
- `zeroRatedSales` و`exemptSales` = 0 حالياً (كل المبيعات قياسية 15%).
- خانات الإقرار تحاكي نموذج ZATCA.

### 6.6 دفتر الأستاذ للحساب — `GET /accounting/ledger/{accountId}?from&to`
سطراً بسطر برصيد جارٍ لحساب واحد. يحسب **الرصيد الافتتاحي** (الحركات قبل `from` بدقّة) فيكون الرصيد الجاري مطلقاً. الأسطر مرتّبة بـ `date` ثم `entryNo`. يُرجِع `account, openingBalance, closingBalance, rows[]` (كل صف: entryNo, date, memo, source, debit, credit, balance).

### 6.7 أعمار الذمم المدينة — `GET /accounting/receivables?asOf&limit`
من الطلبات غير المدفوعة/الجزئية (`paymentStatus ∈ {UNPAID, PARTIAL, DEFERRED}`، الحالة `!= CANCELLED`، `due = grandTotal − paidTotal > 0.004`). يُحسب في SQL (تجميع في قاعدة البيانات، بلا سقف صفوف)، صف لكل عميل، مبوَّباً بعمر الفاتورة (أيام):
- `current` ≤ 30، `d30` = 31–60، `d60` = 61–90، `d90` > 90.
يُرجِع `buckets, total, customers[], customersTotal, truncated` (قائمة العملاء مسقوفة بـ `limit` = 1..1000 افتراضي 200، لكن مجاميع الدلاء دائماً كاملة).

### 6.8 مؤشرات النظرة العامة — `GET /accounting/overview?from&to&branchId`
تمريران فقط (فترة + تراكمي) + بحث حسابات صغير. يُرجِع:
- `revenue`, `expenses`, `netIncome` (للفترة)
- `vatPayable` (رصيد VAT_PAYABLE للفترة)
- `cash` = رصيد CASH + BANK (تراكمي)
- `receivable` = رصيد AR (تراكمي)
- `assets` = مجموع أرصدة الأصول (تراكمي)

### 6.9 الملخص القديم — `GET /accounting/summary?from&to`
P&L مبسّط من الجداول التشغيلية مباشرةً (لا من الدفتر): `revenue = Σ grandTotal` للطلبات غير الملغاة، `expenses = Σ amount` للمصروفات، `net = revenue − expenses`. الفترة الافتراضية = الشهر الجاري.

---

## 7. الأصول والإهلاك (`AssetsService` + `AssetsController`)

الأصل = JSON في `Setting` بمفتاح `asset:{orgId}:{id}` (لا جدول جديد). الحقول: `name, category, branchId?, cost, purchaseDate, usefulLifeMonths, salvageValue, method='STRAIGHT_LINE', accumulatedDepreciation, lastDepreciationDate, status, acquisitionPosted, note, disposedDate?, disposalProceeds?, disposalGain?`.

- `category ∈ {EQUIPMENT, VEHICLE, FURNITURE, COMPUTER, OTHER}`.
- `status ∈ {ACTIVE, DISPOSED}`.

العمليات:

1. **التسجيل + الاقتناء** — `POST /assets`: `cost > 0`، `usefulLifeMonths` 1..600، `salvageValue < cost`. إن `paidFrom ∈ {CASH,BANK,AP}` يُرحَّل قيد الاقتناء (§5.13) ويُضبَط `acquisitionPosted=true`؛ إن `NONE` يُسجَّل الأصل فقط (موجود بالفعل على الدفاتر).
2. **الإهلاك** — `POST /assets/{id}/depreciate`: يُرحَّل لكل شهر كامل مستحق منذ آخر إهلاك حتى الآن. `wholeMonths` تحسب الأشهر الكاملة (تنقص شهراً إن كان يوم النهاية أصغر من يوم البداية). المبلغ لا يتجاوز المتبقّي القابل للإهلاك. إذا اكتمل الإهلاك يُقدَّم التاريخ فقط بلا قيد. يُحدَّث `accumulatedDepreciation` و`lastDepreciationDate`.
3. **الاستبعاد** — `POST /assets/{id}/dispose`: `proceeds ≥ 0`، `via ∈ {CASH,BANK}`. يُلحَق الإهلاك حتى تاريخ الاستبعاد ثم يُرحَّل قيد الاستبعاد بربح/خسارة (§5.15) ويُضبَط `status=DISPOSED`. لا يمكن استبعاد أصل مستبعد مسبقاً.
4. **الحذف** — `DELETE /assets/{id}`: مسموح **فقط** لأصل بلا أثر محاسبي (`accumulatedDepreciation = 0` و`acquisitionPosted = false`)؛ غير ذلك يجب الاستبعاد.
5. **cron** — `runDue(orgId)`: يُهلك كل أصل نشط (يُستدعى من الجدولة).

القيم المحسوبة في العرض (`GET /assets`): `bookValue = cost − accumulatedDepreciation`، `monthlyDepreciation`، مع مجاميع (`totalCost, totalAccumulated, totalBookValue, activeCount`) للأصول النشطة فقط.

---

## 8. الميزانيات (`BudgetsController`)

سطر ميزانية = JSON في `Setting` بمفتاح `budget:{orgId}:{id}`: `{branchId?, category, month:"YYYY-MM", amount, note?}`. الفئات = نفس فئات المصروف الخمس. الفعلي يأتي من جدول `Expense`.

- **العرض** — `GET /budgets?month=YYYY-MM`: يقارن كل سطر بالفعلي المُرحَّل للشهر، مجمّعاً بـ branch+category. `variance = amount − actual`، `pct = actual/amount×100`. `overBudget` = عدد الأسطر التي تجاوز فعليها الميزانية.
  - سطر على مستوى المنشأة (`branchId=null`) يُقارَن بمجموع الفئة داخل النطاق؛ لكنه **يُخفى** عند اختيار فرع محدّد (`X-Branch-Id`) حتى لا يُقارَن بفعلي جزئي مضلّل.
  - `totalActual` يحسب كل مصروف في فئة مُوازَنة **مرة واحدة** (تجنّب الازدواج بين سطر منشأة وسطر فرع بنفس الفئة).
- **الإنشاء/التحديث** — `POST /budgets`: upsert بمفتاح فريد (branch + category + month) — يُحدَّث في مكانه إن وُجد. `month` يطابق `^\d{4}-(0[1-9]|1[0-2])$`.
- `PUT /budgets/{id}` يحدّث المبلغ والبيان؛ `DELETE /budgets/{id}` يحذف.

الميزانيات لا تُرحَّل أي قيود محاسبية — أداة تخطيط ومقارنة فقط.

---

## 9. المصروفات (`AccountingController` — expenses)

- **الفئات**: `OPEX, PAYROLL, RENT, UTILITIES, SUPPLIES` (تُطابَق بـ systemKey لحساب المصروف).
- **العرض** — `GET /accounting/expenses?from&to`: الأحدث أولاً، حد 200 صف.
- **الترحيل** — `POST /accounting/expenses`: `amount > 0`، `vatAmount ≤ amount`، `paidFrom ∈ {CASH,BANK,AP}` (افتراضي CASH). يخضع لقفل الفترة (تاريخ يختاره المستخدم). يُنشئ صف `Expense` + يُرحَّل القيد (§5.10) داخل معاملة، ويُخزَّن `journalEntryId`. يُسجَّل في `AuditTrail`.
- **الحذف بالعكس** — `DELETE /accounting/expenses/{id}`: يُرحَّل قيداً عكسياً (`ExpenseReversal`) قبل حذف الصف — القيد الأصلي لا يُحذف أبداً، والدفتر يصفّر.

---

## 10. الذمم الدائنة (AP) والقوالب المتكررة (`PayablesService` + `PayablesController`)

نظام استحقاق كامل يعيد استخدام محرك القيد المزدوج. لا تغيير في المخطط: الفواتير صفوف `Expense`، والباقي JSON في `Setting`.

### 10.1 فاتورة المورد (payable)
- التخزين: صف `Expense` بـ `paidFrom='AP'` + بيانات في `Setting` بمفتاح `payable:{orgId}:{expenseId}`: `{supplierId?, billNo?, issueDate, dueDate, status, paidAt?, paidVia?, paidJournalId?, recurringId?}`. `status ∈ {OPEN, PAID}`.
- **الإنشاء** — `POST /payables`: `amount > 0`، `vatAmount ≤ amount`، `category` من الخمس، `dueDate` إلزامي، `supplierId` (إن وُجد) مملوك للمنشأة. يُرحَّل قيد المصروف بـ AP (Dr المصروف net + Dr INPUT_VAT / Cr AP gross — §5.10).
- **العرض** — `GET /payables`: فواتير مفتوحة ومدفوعة، مع دلاء أعمار (current, d1_30, d31_60, d61_90, d90p) و`daysOverdue`، وملخّص (`totalOpen, overdue, dueSoon` [خلال 7 أيام], `paidThisMonth, openCount, aging`).
- **السداد** — `POST /payables/{expenseId}/pay`: `via ∈ {CASH,BANK}`. يُرحَّل التسوية (Dr AP / Cr Cash|Bank — §5.12) ويقلب الحالة PAID.
- **الإلغاء (void)** — `DELETE /payables/{expenseId}`: فقط لفاتورة **مفتوحة** — يُرحَّل عكس قيد الاستحقاق (`ExpenseReversal`) ثم يُحذف صف Expense وبياناته. الفاتورة المدفوعة لا تُحذف (أنشئ تسوية بدلاً منها).
- **الموردون** — `GET /payables/suppliers`: قائمة + رصيد كل مورد المفتوح (مجموع فواتيره OPEN).

### 10.2 القوالب المتكررة (recurring)
- التخزين: `Setting` بمفتاح `recurring:{orgId}:{id}`: `{name, category, amount, vatAmount?, supplierId?, branchId?, paidFrom, frequency, anchorDay, dueDays, nextRun, lastRun, generatedCount, active, description?}`.
- `frequency ∈ {WEEKLY, MONTHLY, YEARLY}`، `paidFrom ∈ {AP, CASH, BANK}`، `anchorDay` 1..31، `dueDays` 0..180.
- **التوليد (materialize)**: يُنشئ لقالب `paidFrom=AP` فاتورةً (بتاريخ استحقاق = الحدوث + dueDays)، ولغيرها مصروفاً مدفوعاً مباشرةً. **يُؤرَّخ بتاريخ الفترة المجدولة لا "الآن"**، ثم يقدّم `nextRun` فترةً واحدة من تاريخ الحدوث (لا من الآن) — فيتقدّم قالب متأخّر بـ N فترات خطوةً واحدة لكل استدراك.
- **حساب nextRun**: WEEKLY = +أسبوع، YEARLY = +سنة، MONTHLY = يوم `anchorDay` من الشهر التالي (مقصوص لطول الشهر).
- **runDue(orgId)** (API "run now" + cron يومي): يشغّل كل قالب نشط مستحقّ (`nextRun ≤ اليوم`)، ويستدرك كل فترة فائتة (حارس حتى 60 تكراراً لكل قالب).
- `POST /payables/recurring/{id}/run`: يولّد حدوثاً واحداً الآن.

---

## 11. قفل الفترة (`BooksLock`)

بمجرّد تقديم إقرار ضريبي لفترة، يجب أن تتجمّد أرقامها.

- التخزين: JSON في `Setting` (نوع `accounting.lock`, id `period`) عبر `OrgStore` — لا تغيير مخطط. حقل واحد `closedThrough` لكل منشأة: كل ما هو **في أو قبل** هذا التاريخ مجمّد.
- **`assertOpen(orgId, date)`**: يرفض (422) أي قيد يختار المستخدم تاريخه ويقع داخل الفترة المقفلة. تاريخ `null` (= الآن) مفتوح دائماً (لا يمكن إقفال المستقبل).
- **النطاق المقصود**: يحرس **فقط** القيود التي يختار المستخدم تاريخها — القيد اليدوي (`storeJournal`) والمصروف (`storeExpense`). القيود التلقائية للأحداث الواقعية (مبيعة، دفعة، إشعار دائن إلغاء) **لا تُحجب أبداً** لأنها مؤرَّخة بالحدث نفسه، وإلا لانحرف الدفتر عن العمليات.
- **الضبط** — `PUT /accounting/period-lock {closedThrough: "YYYY-MM-DD" | null}`: **للمالك فقط** (`requireSuperAdmin`). تمرير `null` يعيد فتح الدفاتر بالكامل. كلا الاتجاهين (إقفال/إعادة فتح) يُدقَّق في `AuditTrail` بنوع `PERIOD_LOCK`.
- **العرض** — `GET /accounting/period-lock`: يُرجِع `closedThrough` (أو null).

---

## 12. قواعد البيزنس (مرقّمة)

1. لا يُكتب أي `JournalEntry` إلا عبر `postJournalEntry` (تحقق التوازن + entryNo + عدم التكرار).
2. كل قيد ≥ سطرين غير صفريين بعد إسقاط الأسطر الصفرية.
3. Σمدين = Σدائن بدقّة الهللات (مقارنة أعداد صحيحة `×100`).
4. عدم التكرار على `(orgId, source, refType, refId)` — إعادة الترحيل تُعيد القيد الموجود.
5. `entryNo` = MAX+1 لكل منشأة، فريد على `(orgId, entryNo)`، مع إعادة محاولة حتى 8 مرات عند تصادم 23505.
6. القيد يُؤرَّخ بتاريخ المستند المصدر لا لحظة الترحيل.
7. القيود النظامية (`source != MANUAL`) لا تُعدَّل ولا تُحذف — التصحيح بقيد عكسي فقط.
8. حساب `JournalSource` وحساب `PaymentMethod` قِيَمهما مغلقة (Postgres enum) — التمييز عبر `refType` لا بقيمة enum جديدة.
9. `grossSales = grandTotal − taxTotal + discountTotal` في قيد المبيعة والإشعار الدائن.
10. الإشعار الدائن للإلغاء يعكس الإيراد **والضريبة** معاً (معالجة ZATCA).
11. شحن المحفظة/تحصيل الاشتراك/استبدال الولاء تُقيَّد مقابل DEFERRED_REVENUE لا Sales؛ الاستهلاك ينقل الالتزام (Dr DEFERRED / Cr AR).
12. الدفع/الاستهلاك بالمحفظة لا يُنشئ إيراداً جديداً — الإيراد يُعترَف مرة واحدة في المبيعة عبر SALES.
13. الأصل: الإهلاك يُرحَّل بمرجع مرتبط بالفترة `assetId:YYYY-MM` وإلا أُسقِط كل شهر بعد الأول.
14. ربح/خسارة الاستبعاد على حساب مخصّص لا على SALES/DEP_EXPENSE.
15. حذف المصروف/إلغاء الفاتورة = قيد عكسي قبل حذف الصف؛ الفاتورة المدفوعة لا تُحذف.
16. القيد اليدوي والمصروف يخضعان لقفل الفترة؛ القيود التلقائية لا.
17. إعادة فتح/إقفال الدفاتر للمالك فقط ومُدقَّق.
18. الحساب النظامي: لا يُعطَّل، ولا يُعدَّل رمزه أو نوعه؛ فقط الاسم قابل للتعديل.
19. كل حسابات القيد اليدوي يجب أن تكون مملوكة للمنشأة، وإجمالي المدين > 0.
20. عزل المستأجر: كل استعلام محاسبي مقيّد بـ `orgId`؛ منشأة الدفاتر المحجوزة تُستبعد من قوائم المستأجرين.

---

## 13. الأدوار والصلاحيات + قائمة العمليات الكاملة

**البوابات المستخدمة**: `requireManager` (SUPER_ADMIN أو BRANCH_MANAGER)، `requireSuperAdmin` (المالك فقط). متحكّمات الأصول/الميزانيات/الذمم الدائنة تفحص الدور صراحةً ضد `['SUPER_ADMIN','BRANCH_MANAGER']`.

| العملية | المسار | الطريقة | الصلاحية |
|---|---|---|---|
| عرض دليل الحسابات | `/accounting/accounts` | GET | requireManager |
| إضافة حساب مخصّص | `/accounting/accounts` | POST | requireManager |
| تعديل/تعطيل حساب | `/accounting/accounts/{id}` | PATCH | requireManager |
| عرض دفتر اليومية | `/accounting/journal` | GET | requireManager |
| عرض قيد | `/accounting/journal/{id}` | GET | requireManager |
| ترحيل قيد يدوي | `/accounting/journal` | POST | requireManager (+قفل فترة) |
| عكس قيد | `/accounting/journal/{id}/reverse` | POST | requireManager |
| دفتر أستاذ حساب | `/accounting/ledger/{accountId}` | GET | requireManager |
| ميزان المراجعة | `/accounting/trial-balance` | GET | requireManager |
| قائمة الدخل | `/accounting/income-statement` | GET | requireManager |
| الميزانية العمومية | `/accounting/balance-sheet` | GET | requireManager |
| إقرار الضريبة VAT | `/accounting/vat-return` | GET | requireManager |
| أعمار الذمم | `/accounting/receivables` | GET | requireManager |
| النظرة العامة KPIs | `/accounting/overview` | GET | requireManager |
| الملخص القديم | `/accounting/summary` | GET | requireManager |
| عرض/ترحيل/حذف مصروف | `/accounting/expenses` | GET/POST/DELETE | requireManager (+قفل فترة عند POST) |
| عرض قفل الفترة | `/accounting/period-lock` | GET | requireManager |
| ضبط قفل الفترة | `/accounting/period-lock` | PUT | requireSuperAdmin (المالك) |
| الأصول (عرض/تسجيل/إهلاك/استبعاد/حذف) | `/assets…` | GET/POST/DELETE | مدير (SUPER_ADMIN/BRANCH_MANAGER) |
| الميزانيات (عرض/upsert/تحديث/حذف) | `/budgets…` | GET/POST/PUT/DELETE | مدير |
| الذمم الدائنة (فواتير/سداد/إلغاء/موردون) | `/payables…` | GET/POST/DELETE | مدير |
| القوالب المتكررة (CRUD + run) | `/payables/recurring…` | GET/POST/PUT/DELETE | مدير |

- كل عمليات الكتابة تُسجَّل في `AuditTrail` (CREATE/UPDATE/DELETE/PAY/VOID/DEPRECIATE/DISPOSE/PERIOD_LOCK…).
- التوكنات غير الموظفية (بوابة العميل/المورد) تحمل orgId لكنها تُرفَض ضمنياً بعدم امتلاك دور مدير.

---

## 14. حالات خاصة وفجوات

1. **تحصيل اشتراك بالمحفظة لا يُرحَّل قيداً** (§5.6): الفرع wallet في `SubscriptionController::pay` يخصم المحفظة عبر `WalletService::debit` فقط بلا قيد يومية. المبرّر في الكود: القيمة اعتُرِف بها كإيراد مؤجل عند الشحن. لكن خصم المحفظة يقلّل الرصيد دون تقييد مقابل، فلا ينتقل الالتزام المؤجل إلى إيراد/ذمة — يظل DEFERRED_REVENUE مرتفعاً بقيمة الاشتراك المستهلك من المحفظة. نقطة تحتاج مراجعة محاسبية.
2. **الاسترجاعات محمية بأقفال استشارية** (`walletrefund:`, `subrestore:`) داخل معاملة لمنع الازدواج عند الإلغاء المتزامن (زر منقور مرتين)؛ وكلاهما `report($e)` عند الفشل حتى لا يُعطِّل تغيير حالة السلة.
3. **الترحيل الرجعي** — أمر `php artisan accounting:backfill [--org=]`: يشغّل `syncOrderAccounting` (idempotent) على كل الطلبات فيضمن قيد ORDER لكل طلب غير ملغى وغير صفري وقيد PAYMENT لكل دفعة. آمن لإعادة التشغيل. يطبع لقطة قبل/بعد.
4. **أعمار الذمم المدينة مسقوفة في العرض فقط**: قائمة العملاء محدودة بـ `limit` (≤1000) لتفادي استجابة ضخمة، لكن مجاميع الدلاء دائماً كاملة (التجميع في SQL)، مع علم `truncated`.
5. **قيمة `DEFERRED` كطريقة دفع** (بيع آجل): لا تُنشئ صف Payment ولا قيد دفعة — تبقى AR مفتوحة حتى التحصيل الفعلي.
6. **`SALES_DISCOUNTS` مقابل-إيراد** رغم كونه نوع REVENUE: يُقيَّد مديناً ليخفّض صافي الإيراد، ويُستخدم للخصومات واستبدال الولاء.
7. **دفاتر المنصّة على منشأة محجوزة**: تعمل بنفس المحرك/التقارير، لكن معرّفها يُستبعَد من كل قوائم المستأجرين (`AdminTenantController`, `PlatformStats`) عبر `PlatformBooks::isPlatformOrg`.
8. **`ensureChartOfAccounts` كسول ومتحمّل للتزامن**: يُبذِّر المفقود فقط ويتجاهل تعارض التكرار 23505 (مكافئ createMany skipDuplicates)؛ يُستدعى ضمنياً من `systemAccounts` قبل أي ترحيل.
9. **الإهلاك يتوقف عند اكتمال القابل للإهلاك**: إذا `amount ≤ 0` يُقدَّم `lastDepreciationDate` فقط بلا قيد (يمنع الإهلاك تحت القيمة التخريدية).
10. **حذف الأصل مقيّد**: لا يُحذف أصل له `accumulatedDepreciation > 0` أو `acquisitionPosted` — يجب الاستبعاد (يحافظ على سلامة الدفتر).
