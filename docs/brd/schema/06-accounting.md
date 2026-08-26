# مخطط قاعدة البيانات — المحاسبة والأصول والذمم الدائنة

> مصدر المتطلبات: [`../08-accounting-assets-payables.md`](../08-accounting-assets-payables.md).
> هذا مخطط **نضيف** لإعادة البناء (لا يلتزم بقيود Prisma المشتركة الحالية). كل الجداول snake_case جمع، أعمدة snake_case، مفتاح `id` bigint تزايدي، و`legacy_cuid` لاستيراد البيانات القديمة (cuid) من الجداول الحالية `Account/JournalEntry/JournalLine/Expense` ومن مفاتيح `Setting`.

## مبادئ حاكمة على المخطط كله

- **`organization_id`** على كل جدول (عزل المستأجر) — FK إلى `organizations(id)` بـ `onDelete RESTRICT` (لا تُحذف محاسبة منشأة بالتتالي أبداً).
- **الأموال `decimal(14,2)`** حصراً (تحسين نزاهة عن `float` في المخطط القديم — يمنع أخطاء تمثيل العشور).
- **الأنواع/الحالات**: عمود `string` + **PHP enum** يطابق القيم + قيد **`CHECK`** على مستوى القاعدة يحصر القيم المسموحة.
- **`created_at` / `updated_at`** على كل جدول (المخطط القديم كان يُسقط `updatedAt` على `Account/JournalEntry` و كل الطوابع على `JournalLine`؛ المخطط الجديد يوحّدها للتدقيق).
- **JSON** يُستخدم لـ `metadata` فقط — لا منطق أعمال داخل JSON.
- **`legacy_cuid`** `string nullable unique` على كل جدول قابل للاستيراد — يربط الصف الجديد بالـ cuid القديم أثناء الترحيل ثم يبقى مرجعاً تاريخياً.

### الأنواع (PHP enums + قيم CHECK)

| Enum | القيم | يُستخدم في |
|---|---|---|
| `AccountType` | `ASSET` \| `LIABILITY` \| `EQUITY` \| `REVENUE` \| `EXPENSE` | `accounts.type` |
| `JournalSource` | `MANUAL` \| `ORDER` \| `PAYMENT` \| `REFUND` \| `EXPENSE` \| `WALLET_TOPUP` \| `OPENING` | `journal_entries.source` |
| `ExpenseCategory` | `OPEX` \| `PAYROLL` \| `RENT` \| `UTILITIES` \| `SUPPLIES` | `expenses.category`, `budgets.category`, `recurring_bills.category` |
| `ExpensePaidFrom` | `CASH` \| `BANK` \| `AP` | `expenses.paid_from` |
| `PayableStatus` | `OPEN` \| `PAID` | `payables.status` |
| `SettleVia` | `CASH` \| `BANK` | `payables.paid_via`, `fixed_assets.disposal_via` |
| `RecurringFrequency` | `WEEKLY` \| `MONTHLY` \| `YEARLY` | `recurring_bills.frequency` |
| `RecurringPaidFrom` | `AP` \| `CASH` \| `BANK` | `recurring_bills.paid_from` |
| `AssetCategory` | `EQUIPMENT` \| `VEHICLE` \| `FURNITURE` \| `COMPUTER` \| `OTHER` | `fixed_assets.category` |
| `AssetStatus` | `ACTIVE` \| `DISPOSED` | `fixed_assets.status` |
| `DepreciationMethod` | `STRAIGHT_LINE` | `fixed_assets.method` |

> **ملاحظة enum مغلق**: `JournalSource` قِيَمه سبعة فقط. التدفقات الجديدة (الاشتراك، الولاء، الأصول، تسوية المورد…) تُميَّز بـ `ref_type` لا بقيمة `source` جديدة — مطابقة لسلوك المخطق القديم المشترك. `PaymentMethod` (CASH/CARD/TRANSFER/WALLET/DEFERRED) مملوك لموديول المدفوعات (05) لا لهذا الموديول.

---

## الجداول

### `accounts`  ← كان: `Account`
> دليل (شجرة) الحسابات لكل منشأة، هرمي ذاتي المرجع. الرصيد **لا يُخزَّن** — يُحسب دائماً من مجاميع `journal_lines`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | مفتاح أولي تزايدي |
| `legacy_cuid` | string | نعم | null | unique — cuid القديم عند الاستيراد |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `code` | string(20) | لا | — | رمز الحساب |
| `name` | string(120) | لا | — | الاسم العربي (2–120) |
| `name_en` | string(120) | نعم | null | الاسم الإنجليزي |
| `type` | string | لا | — | `AccountType` + CHECK |
| `parent_id` | bigint | نعم | null | FK → `accounts(id)` `RESTRICT` (حساب أب — بناء الشجرة) |
| `is_system` | boolean | لا | false | حساب نظامي مُبذَّر — محمي من التعديل الجوهري والحذف |
| `system_key` | string(40) | نعم | null | مفتاح النظام (`CASH`, `AR`, `VAT_PAYABLE`…) يربطه بمنطق الترحيل التلقائي |
| `is_active` | boolean | لا | true | مفعّل/معطّل |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `UNIQUE (organization_id, code)` — الرمز فريد داخل المنشأة.
  - `UNIQUE (organization_id, system_key) WHERE system_key IS NOT NULL` — مفتاح نظام واحد لكل منشأة (يمنع تكرار `CASH`… ويثبّت البذر idempotent).
  - `INDEX (parent_id)` — اجتياز الشجرة.
  - `INDEX (organization_id, type)` — تجميع التقارير حسب النوع.
  - `CHECK type IN ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE')`.
  - `CHECK (parent_id IS NULL OR parent_id <> id)` — منع الأب الذاتي المباشر.
- **علاقات:** `parent` (belongsTo self) / `children` (hasMany self)؛ `journalLines` (hasMany)؛ `organization` (belongsTo).
- **حماية القيود التطبيقية:** الحساب النظامي (`is_system=true`) لا يُعطَّل ولا يُغيَّر `code`/`type`/`system_key`؛ الاسم فقط قابل للتعديل. تُفرض في طبقة الخدمة لا في القاعدة.

### `journal_entries`  ← كان: `JournalEntry`
> رأس قيد يومية متوازن. لا يُكتب إلا عبر `postJournalEntry` (تحقق توازن + `entry_no` + عدم تكرار).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `entry_no` | integer | لا | — | تسلسلي لكل منشأة = MAX+1 |
| `date` | timestamp | لا | — | **تاريخ المستند المصدر** (يحدد الفترة المحاسبية) لا لحظة الترحيل |
| `memo` | string(255) | نعم | null | بيان القيد |
| `source` | string | لا | — | `JournalSource` + CHECK |
| `ref_type` | string(40) | نعم | null | نوع المرجع (`Order`, `Payment`, `Subscription`, `AssetAcquisition`…) |
| `ref_id` | string(120) | نعم | null | معرّف المستند المرجعي — **string** لأنه قد يكون مركّباً (`assetId:YYYY-MM` للإهلاك) |
| `branch_id` | bigint | نعم | null | FK → `branches(id)` `SET NULL` — مركز التكلفة |
| `created_by_id` | bigint | نعم | null | FK → `users(id)` `SET NULL` |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `UNIQUE (organization_id, entry_no)` — ترقيم متسلسل لكل منشأة (تصادم 23505 يُعالَج بإعادة محاولة تطبيقية حتى 8 مرات).
  - **`UNIQUE (organization_id, source, ref_type, ref_id) WHERE ref_id IS NOT NULL`** — **فهرس عدم التكرار (idempotency)**؛ إعادة الترحيل بنفس الرباعية تُعيد القيد الموجود بدل كتابة جديد (أساس `syncOrderAccounting` والترحيل الرجعي). القيد اليدوي (`ref_id = null`) خارج الحارس لأنه قد يتكرر مشروعاً.
  - `INDEX (organization_id, date)` — قصّ التقارير بالفترة.
  - `INDEX (organization_id, source, ref_type)` — فلترة دفتر اليومية.
  - `INDEX (branch_id)`.
  - `CHECK source IN ('MANUAL','ORDER','PAYMENT','REFUND','EXPENSE','WALLET_TOPUP','OPENING')`.
- **توازن القيد (Σمدين = Σدائن) يُفرَض تطبيقياً**، لا بقيد قاعدة: `postJournalEntry` يقارن مجموع `debit` و`credit` **بالأعداد الصحيحة من الهللات** `(int) round(total*100)` قبل الكتابة داخل معاملة، ويرمي `UNBALANCED_ENTRY` عند الاختلاف. (قيد CHECK لا يستطيع تجميع عدة صفوف أبناء، لذا الحارس في المحرك.)
- **علاقات:** `lines` (hasMany `journal_lines`)؛ `branch`، `createdBy`، `organization`.

### `journal_lines`  ← كان: `JournalLine`
> سطر مدين/دائن واحد ضمن قيد. في كل سطر إما `debit` أو `credit` موجب (لا الاثنان بعد إسقاط الأسطر الصفرية).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` (منسوخ من القيد — تسريع استعلامات العزل) |
| `journal_entry_id` | bigint | لا | — | FK → `journal_entries(id)` `CASCADE` (السطر يتبع قيده) |
| `account_id` | bigint | لا | — | FK → `accounts(id)` `RESTRICT` (لا يُحذف حساب له أسطر) |
| `debit` | decimal(14,2) | لا | 0 | المبلغ المدين (≥0) |
| `credit` | decimal(14,2) | لا | 0 | المبلغ الدائن (≥0) |
| `branch_id` | bigint | نعم | null | FK → `branches(id)` `SET NULL` — يُنسخ من القيد |
| `memo` | string(255) | نعم | null | بيان السطر |
| `created_at` | timestamp | لا | now | (إضافة جديدة — المخطط القديم بلا طوابع) |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `INDEX (account_id)` و`INDEX (organization_id, account_id)` — تجميع أرصدة الحسابات (`balancesByAccount`).
  - `INDEX (journal_entry_id)` — جلب أسطر القيد.
  - `CHECK (debit >= 0 AND credit >= 0)`.
  - `CHECK (debit = 0 OR credit = 0)` — لا يجتمع مدين ودائن على سطر واحد.
- **علاقات:** `entry` (belongsTo `journal_entries`)؛ `account` (belongsTo)؛ `branch`.

### `expenses`  ← كان: `Expense`
> مصروف مُرحَّل. `paid_from='AP'` = فاتورة مورد مستحقة (يرافقها صف في `payables`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `branch_id` | bigint | نعم | null | FK → `branches(id)` `SET NULL` |
| `date` | timestamp | لا | — | تاريخ المصروف (يحدد الفترة) |
| `category` | string | لا | — | `ExpenseCategory` + CHECK |
| `description` | string(255) | نعم | null | البيان |
| `amount` | decimal(14,2) | لا | — | الإجمالي شامل الضريبة (>0) |
| `vat_amount` | decimal(14,2) | لا | 0 | ضريبة المدخلات القابلة للخصم (≤ amount) |
| `account_id` | bigint | لا | — | FK → `accounts(id)` `RESTRICT` (حساب المصروف من الدليل) |
| `paid_from` | string | لا | 'CASH' | `ExpensePaidFrom` + CHECK |
| `reference` | string(100) | نعم | null | مرجع خارجي |
| `journal_entry_id` | bigint | نعم | null | FK → `journal_entries(id)` `RESTRICT` (القيد المُرحَّل المرتبط) |
| `created_by_id` | bigint | نعم | null | FK → `users(id)` `SET NULL` |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `INDEX (organization_id, date)` — عرض المصروفات (الأحدث أولاً).
  - `INDEX (organization_id, category)` — مقارنة الميزانيات بالفعلي.
  - `INDEX (branch_id)`، `INDEX (account_id)`، `INDEX (journal_entry_id)`.
  - `CHECK category IN ('OPEX','PAYROLL','RENT','UTILITIES','SUPPLIES')`.
  - `CHECK paid_from IN ('CASH','BANK','AP')`.
  - `CHECK (amount > 0 AND vat_amount >= 0 AND vat_amount <= amount)`.
- **علاقات:** `account`، `journalEntry`، `branch`، `payable` (hasOne — إن كانت `paid_from='AP'`).

### `payables`  ← كان: `Setting` بمفتاح `payable:{orgId}:{expenseId}`
> فاتورة مورد مستحقة على حساب الذمم الدائنة (AP). صف واحد لكل مصروف `paid_from='AP'`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique — كان يحمل `expenseId` من مفتاح Setting |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `expense_id` | bigint | لا | — | FK → `expenses(id)` `CASCADE` (الإلغاء يحذف المصروف فيتتالى) — **unique** |
| `supplier_id` | bigint | نعم | null | FK → `suppliers(id)` `RESTRICT` |
| `bill_no` | string(100) | نعم | null | رقم فاتورة المورد |
| `issue_date` | date | لا | — | تاريخ الإصدار |
| `due_date` | date | لا | — | تاريخ الاستحقاق (إلزامي — أساس أعمار الذمم) |
| `status` | string | لا | 'OPEN' | `PayableStatus` + CHECK |
| `paid_at` | timestamp | نعم | null | لحظة السداد |
| `paid_via` | string | نعم | null | `SettleVia` (CASH/BANK) + CHECK |
| `paid_journal_entry_id` | bigint | نعم | null | FK → `journal_entries(id)` `RESTRICT` (قيد التسوية) |
| `recurring_bill_id` | bigint | نعم | null | FK → `recurring_bills(id)` `SET NULL` (القالب المولِّد) |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `UNIQUE (expense_id)` — علاقة 1:1 مع المصروف.
  - `INDEX (organization_id, status)` — قوائم المفتوح/المدفوع.
  - `INDEX (organization_id, due_date)` — دلاء الأعمار والمتأخر.
  - `INDEX (supplier_id)` — رصيد المورد المفتوح.
  - `INDEX (recurring_bill_id)`.
  - `CHECK status IN ('OPEN','PAID')`.
  - `CHECK (paid_via IS NULL OR paid_via IN ('CASH','BANK'))`.
  - `CHECK (status <> 'PAID' OR (paid_at IS NOT NULL AND paid_journal_entry_id IS NOT NULL))` — الفاتورة المدفوعة تحمل لحظة وقيد تسوية.
- **علاقات:** `expense` (belongsTo)، `supplier`، `paidJournalEntry`، `recurringBill`.
- **قاعدة تطبيقية:** الفاتورة `PAID` لا تُحذف (أنشئ قيد تسوية)؛ الإلغاء `OPEN` يُرحّل عكس الاستحقاق قبل حذف المصروف.

### `recurring_bills`  ← كان: `Setting` بمفتاح `recurring:{orgId}:{id}`
> قالب فاتورة/مصروف متكرر يولّد صفوفاً مجدولة (فاتورة AP أو مصروف مدفوع مباشرةً).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `name` | string(120) | لا | — | اسم القالب |
| `category` | string | لا | — | `ExpenseCategory` + CHECK |
| `amount` | decimal(14,2) | لا | — | المبلغ الإجمالي (>0) |
| `vat_amount` | decimal(14,2) | لا | 0 | جزء الضريبة (≤ amount) |
| `supplier_id` | bigint | نعم | null | FK → `suppliers(id)` `SET NULL` |
| `branch_id` | bigint | نعم | null | FK → `branches(id)` `SET NULL` |
| `paid_from` | string | لا | — | `RecurringPaidFrom` (AP/CASH/BANK) + CHECK |
| `frequency` | string | لا | — | `RecurringFrequency` + CHECK |
| `anchor_day` | smallint | لا | — | يوم الإرساء 1..31 (للشهري) |
| `due_days` | smallint | لا | 0 | أيام حتى الاستحقاق 0..180 (لفواتير AP) |
| `next_run` | date | لا | — | موعد التوليد التالي |
| `last_run` | date | نعم | null | آخر توليد فعلي |
| `generated_count` | integer | لا | 0 | عدد الحدوثات المولَّدة |
| `is_active` | boolean | لا | true | مفعّل |
| `description` | string(255) | نعم | null | بيان يُنسخ للمصروف/الفاتورة |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `INDEX (organization_id, is_active, next_run)` — `runDue` يلتقط القوالب المستحقة (`is_active AND next_run <= today`).
  - `INDEX (supplier_id)`، `INDEX (branch_id)`.
  - `CHECK category IN ('OPEX','PAYROLL','RENT','UTILITIES','SUPPLIES')`.
  - `CHECK paid_from IN ('AP','CASH','BANK')`.
  - `CHECK frequency IN ('WEEKLY','MONTHLY','YEARLY')`.
  - `CHECK (anchor_day BETWEEN 1 AND 31)`.
  - `CHECK (due_days BETWEEN 0 AND 180)`.
  - `CHECK (amount > 0 AND vat_amount >= 0 AND vat_amount <= amount)`.
- **علاقات:** `supplier`، `branch`، `generatedPayables` (hasMany `payables` عبر `recurring_bill_id`).

### `fixed_assets`  ← كان: `Setting` بمفتاح `asset:{orgId}:{id}`
> أصل ثابت يُهلَك بالقسط الثابت. الرصيد الدفتري محسوب (`cost − accumulated_depreciation`) لا مخزَّن.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `branch_id` | bigint | نعم | null | FK → `branches(id)` `SET NULL` |
| `name` | string(120) | لا | — | اسم الأصل |
| `category` | string | لا | — | `AssetCategory` + CHECK |
| `cost` | decimal(14,2) | لا | — | التكلفة (>0) |
| `purchase_date` | date | لا | — | تاريخ الشراء |
| `useful_life_months` | integer | لا | — | العمر الإنتاجي 1..600 |
| `salvage_value` | decimal(14,2) | لا | 0 | القيمة التخريدية (< cost) |
| `method` | string | لا | 'STRAIGHT_LINE' | `DepreciationMethod` + CHECK |
| `accumulated_depreciation` | decimal(14,2) | لا | 0 | مجمع الإهلاك حتى الآن |
| `last_depreciation_date` | date | نعم | null | تاريخ آخر إهلاك مُرحَّل |
| `status` | string | لا | 'ACTIVE' | `AssetStatus` + CHECK |
| `acquisition_posted` | boolean | لا | false | هل رُحّل قيد الاقتناء (paid_from ≠ NONE) |
| `acquisition_paid_from` | string | نعم | null | مصدر شراء الاقتناء (`CASH`/`BANK`/`AP`/`NONE`) — توثيق |
| `acquisition_journal_entry_id` | bigint | نعم | null | FK → `journal_entries(id)` `RESTRICT` |
| `note` | string(255) | نعم | null | ملاحظة |
| `disposed_date` | date | نعم | null | تاريخ الاستبعاد |
| `disposal_proceeds` | decimal(14,2) | نعم | null | متحصّلات البيع (≥0) |
| `disposal_gain` | decimal(14,2) | نعم | null | ربح/خسارة الاستبعاد (موجب/سالب) |
| `disposal_via` | string | نعم | null | `SettleVia` (CASH/BANK) + CHECK |
| `disposal_journal_entry_id` | bigint | نعم | null | FK → `journal_entries(id)` `RESTRICT` |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `INDEX (organization_id, status)` — مجاميع الأصول النشطة و`runDue`.
  - `INDEX (branch_id)`، `INDEX (organization_id, category)`.
  - `CHECK category IN ('EQUIPMENT','VEHICLE','FURNITURE','COMPUTER','OTHER')`.
  - `CHECK status IN ('ACTIVE','DISPOSED')`.
  - `CHECK method = 'STRAIGHT_LINE'`.
  - `CHECK (cost > 0 AND salvage_value >= 0 AND salvage_value < cost)`.
  - `CHECK (useful_life_months BETWEEN 1 AND 600)`.
  - `CHECK (accumulated_depreciation >= 0 AND accumulated_depreciation <= cost - salvage_value)`.
  - `CHECK (disposal_via IS NULL OR disposal_via IN ('CASH','BANK'))`.
- **علاقات:** `branch`، `acquisitionJournalEntry`، `disposalJournalEntry`، `depreciationEntries` (hasMany).
- **قاعدة تطبيقية:** لا يُحذف أصل له `accumulated_depreciation > 0` أو `acquisition_posted=true` (يجب الاستبعاد) — يحافظ على سلامة الدفتر.

### `asset_depreciation_entries`  ← جديد (جدولة/سجل إهلاك — كان مضمَّناً في refId `assetId:YYYY-MM`)
> سجل قسط إهلاك واحد لكل (أصل، شهر). يجعل حارس عدم التكرار الشهري صريحاً بدل تحليل نص `ref_id`، ويعطي جدول إهلاك تدقيقياً نظيفاً.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `fixed_asset_id` | bigint | لا | — | FK → `fixed_assets(id)` `CASCADE` |
| `period` | char(7) | لا | — | الفترة `YYYY-MM` (يطابق مقطع `ref_id`) |
| `amount` | decimal(14,2) | لا | — | قسط الشهر المُرحَّل (>0) |
| `journal_entry_id` | bigint | لا | — | FK → `journal_entries(id)` `RESTRICT` (قيد الإهلاك) |
| `posted_at` | timestamp | لا | now | لحظة الترحيل |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - **`UNIQUE (fixed_asset_id, period)`** — قسط واحد لكل شهر (يعكس idempotency `refId=assetId:YYYY-MM`؛ يمنع ازدواج الإهلاك).
  - `INDEX (organization_id, period)`.
  - `CHECK period ~ '^\d{4}-(0[1-9]|1[0-2])$'`.
  - `CHECK amount > 0`.
- **علاقات:** `asset` (belongsTo `fixed_assets`)، `journalEntry`.

### `budgets`  ← كان: `Setting` بمفتاح `budget:{orgId}:{id}`
> سطر ميزانية تخطيطية (لا يُرحّل أي قيد). الفعلي يأتي من `expenses`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `legacy_cuid` | string | نعم | null | unique |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` |
| `branch_id` | bigint | نعم | null | FK → `branches(id)` `SET NULL` (null = مستوى المنشأة) |
| `category` | string | لا | — | `ExpenseCategory` + CHECK |
| `month` | char(7) | لا | — | `YYYY-MM` |
| `amount` | decimal(14,2) | لا | — | المبلغ المُوازَن (>0) |
| `note` | string(255) | نعم | null | بيان |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:** (مفتاح upsert = branch + category + month؛ `branch_id` قابل لـ null فيلزم فهرسان جزئيان لأن NULL متمايز في Postgres)
  - `UNIQUE (organization_id, branch_id, category, month) WHERE branch_id IS NOT NULL` — سطر الفرع.
  - `UNIQUE (organization_id, category, month) WHERE branch_id IS NULL` — سطر مستوى المنشأة.
  - `INDEX (organization_id, month)` — عرض `GET /budgets?month=`.
  - `CHECK category IN ('OPEX','PAYROLL','RENT','UTILITIES','SUPPLIES')`.
  - `CHECK month ~ '^\d{4}-(0[1-9]|1[0-2])$'`.
  - `CHECK amount > 0`.
- **علاقات:** `branch` (belongsTo)، `organization`.

### `books_locks`  ← كان: `Setting` نوع `accounting.lock` id `period` (عبر `OrgStore`)
> قفل الفترة المحاسبية — صف واحد لكل منشأة. كل ما هو في/قبل `closed_through` مجمّد.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|---|---|---|---|---|
| `id` | bigint (PK, auto) | لا | — | |
| `organization_id` | bigint | لا | — | FK → `organizations(id)` `RESTRICT` — **unique** |
| `closed_through` | date | نعم | null | آخر يوم مقفل (null = الدفاتر مفتوحة بالكامل) |
| `updated_by_id` | bigint | نعم | null | FK → `users(id)` `SET NULL` (المالك الذي أقفل/فتح — مُدقَّق أيضاً في سجل التدقيق) |
| `created_at` | timestamp | لا | now | |
| `updated_at` | timestamp | لا | now | |

- **فهارس/قيود:**
  - `UNIQUE (organization_id)` — قفل واحد لكل منشأة (upsert).
- **قاعدة تطبيقية:** `assertOpen(orgId, date)` يرفض (422) أي قيد يختار المستخدم تاريخه ويقع ≤ `closed_through` (القيد اليدوي والمصروف فقط)؛ تاريخ `null` (=الآن) مفتوح دائماً؛ الضبط للمالك فقط (`requireSuperAdmin`).
- **علاقات:** `organization`، `updatedBy`.

---

## البذر والحماية (ملاحظة إلزامية)

- **الحسابات النظامية تُبذَر لكل منشأة**: عند تهيئة المنشأة (`ensureChartOfAccounts`) تُنشأ الحسابات الافتراضية الـ17 (§3.1 من BRD) بـ `is_system=true` — idempotent، يتخطى الموجود بأمان بفضل `UNIQUE (organization_id, code)` و`UNIQUE (organization_id, system_key)`. حسابات الأصول الخمسة (§3.2) وحسابات دفاتر المنصّة (§3.3) تُبذَر كسولاً عند أول استخدام.
- **`system_key` محمي**: الحساب `is_system=true` لا يُعطَّل ولا يُغيَّر `code`/`type`/`system_key` — فقط `name`/`name_en` قابلان للتعديل (تُفرض في الخدمة). هذا يضمن ثبات ربط منطق الترحيل التلقائي بالحسابات (`CASH`, `AR`, `DEFERRED_REVENUE`…).
- **توازن القيد يُفرَض تطبيقياً** في `postJournalEntry` (مقارنة الهللات صحيحة الأعداد) — لا يوجد قيد CHECK قاعدي قادر على تجميع الأبناء.

---

## تحويلات من `Setting`-JSON إلى جداول

| مفتاح `Setting` القديم | الجدول الجديد | مفتاح الربط / ملاحظة الترحيل |
|---|---|---|
| `asset:{orgId}:{id}` | **`fixed_assets`** | `legacy_cuid = id`؛ حقول JSON → أعمدة؛ الإهلاكات التاريخية تُعاد بناؤها في `asset_depreciation_entries` من قيود `MANUAL/Depreciation`. |
| `budget:{orgId}:{id}` | **`budgets`** | `legacy_cuid = id`؛ upsert على (branch, category, month). |
| `payable:{orgId}:{expenseId}` | **`payables`** | `expense_id` = المصروف المرتبط (كان `expenseId` جزء المفتاح)؛ `legacy_cuid = expenseId`. |
| `recurring:{orgId}:{id}` | **`recurring_bills`** | `legacy_cuid = id`؛ حقول JSON → أعمدة؛ `payables.recurring_bill_id` يربط المولَّدات. |
| `accounting.lock` / `period` | **`books_locks`** | صف واحد لكل منشأة؛ `closed_through` من JSON. |
| `bankrecon:*` (تسوية بنكية) | **خارج نطاق هذا المخطط** | التسوية البنكية مملوكة لموديول المدفوعات/العمليات (05/09)؛ تُصمَّم في ملف مخطط ذلك الموديول لا هنا. مذكورة للاكتمال فقط. |

> ملاحظة عامة للترحيل: كل جدول قابل للاستيراد يحمل `legacy_cuid` (unique) لتتبّع الأصل القديم؛ أرقام `journal_entries.entry_no` تُحفظ كما هي لكل منشأة، و`ref_id` يبقى نصياً لدعم المرجع المركّب `assetId:YYYY-MM`.
