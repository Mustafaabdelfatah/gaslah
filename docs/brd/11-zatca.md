# BRD 11 — الفوترة الإلكترونية ZATCA

> يفترض هذا الملف أن القارئ قد اطّلع على `00-overview-architecture.md` (المصادقة بتوكن HMAC، عزل المستأجرين عبر `ResolvesTenant`، نماذج `PrismaModel`، وقيد تجميد سكيما Prisma).

يوثّق هذا الملف بالكامل تكامل النظام مع منظومة الفوترة الإلكترونية السعودية (ZATCA / Fatoora — فاتورة) بمرحلتيها، بالإضافة إلى التسجيل (onboarding)، التوقيع، المفاتيح، والإبلاغ للهيئة. كل ما ورد مستخرَج حرفياً من الكود، دون ضغط أو حذف.

---

## 1. نظرة عامة (المرحلتان)

منظومة ZATCA تُلزم المكلّفين بإصدار فواتير ضريبية إلكترونية على مرحلتين:

- **المرحلة 1 — التوليد (Generation):** إصدار فاتورة ضريبية مبسّطة مع رمز **QR فوري** يحمل خمسة وسوم أساسية بترميز TLV. لا تُخزَّن، تُبنى عند الطلب في كل مرة (on the fly). المسؤول عنها `App\Support\Zatca` و`ZatcaController`.

- **المرحلة 2 — التكامل (Integration):** إصدار فاتورة **UBL 2.1** كاملة، تُولَّد **مرة واحدة وتُخزَّن** (idempotent) في جدول `ZatcaInvoice`. تحمل هاش SHA-256، وسلسلة فواتير مترابطة (ICV + PIH + UUID)، ورمز QR بستة وسوم (الوسم 6 = الهاش). المسؤول عنها `App\Support\ZatcaPhase2` و`ZatcaPhase2Controller`.

- **التسجيل والتوقيع والإبلاغ:** طبقة إضافية تربط المرحلة 2 ببوابة الهيئة الفعلية — توليد CSR على منحنى secp256k1، الحصول على شهادة امتثال (compliance CSID) عبر OTP، ثم شهادة إنتاج (production CSID)، ثم التوقيع بـ ECDSA/SHA-256 (الوسمان 7 و8)، ثم الإبلاغ. المسؤول عنها `ZatcaCsr`, `ZatcaClient`, `ZatcaSigner`, `ZatcaStore`, و`ZatcaOnboardingController`.

**هذا الكود منقول بأمانة عن التطبيق الأصلي Next.js** (`laundry-system/src/lib/zatca.ts` و`src/server/zatca-invoice.ts`)، مع التطابق البايت-بالبايت في ترميز TLV وبناء XML — مصرّح بذلك في تعليقات الكود.

### توزيع الملفات

| الشأن | الموضع |
| --- | --- |
| الإعدادات والنقاط النهائية | `config/zatca.php` (يقرأ `env('ZATCA_*')`) |
| المرحلة 1 (QR فوري) | `app/Support/Zatca.php` + `ZatcaController` + `routes/api/zatca.php` |
| المرحلة 2 (UBL مخزّن) | `app/Support/ZatcaPhase2.php` + `ZatcaPhase2Controller` + `routes/api/zatca-p2.php` |
| توليد CSR + مفتاح EC | `app/Support/ZatcaCsr.php` (يستدعي `openssl` عبر Process) |
| عميل HTTP للبوابة | `app/Support/ZatcaClient.php` |
| الختم التشفيري (الوسمان 7/8) | `app/Support/ZatcaSigner.php` + `ZatcaPhase2::appendSignatureTags()` |
| حالة التسجيل والتخزين | `app/Support/ZatcaStore.php` |
| التسجيل عبر API | `app/Http/Controllers/Api/ZatcaOnboardingController.php` + `routes/api/zatca-onboarding.php` |
| الأمر المجدول (CLI) | `app/Console/Commands/ZatcaOnboard.php` (`php artisan zatca:onboard`) |

---

## 2. كيان ZatcaInvoice (كل الحقول والحالات)

النموذج `App\Models\ZatcaInvoice` يعكس جدول Prisma `ZatcaInvoice` — صف واحد لكل طلب، ومعرّف cuid نصي.

### الحقول

| الحقل | النوع/الصيغة | الوصف والمصدر |
| --- | --- | --- |
| `id` | string (cuid) | المفتاح الأساسي، يُولَّد عبر `PrismaModel::newCuid()`. |
| `orderId` | string | معرّف الطلب. **فريد (unique)** — أي طلب له فاتورة واحدة على الأكثر. |
| `orgId` | string | معرّف المنشأة (المستأجر). عزل المستأجر يتم عبره. |
| `icv` | integer | عدّاد الفاتورة المتسلسل لكل منشأة (Invoice Counter Value). **فريد مركّب [orgId, icv]**. يبدأ من 1. |
| `uuid` | string | UUID للفاتورة (يُولَّد بـ `Str::uuid()`). يُدرَج في XML ويُرسَل للهيئة. |
| `pih` | string (base64) | هاش الفاتورة السابقة (Previous Invoice Hash). للأولى = `GENESIS_PIH`. |
| `hash` | string (base64) | هاش SHA-256 لـ XML هذه الفاتورة (base64). يغذّي الوسم 6 ويصبح PIH للفاتورة التالية. |
| `xml` | text | فاتورة UBL 2.1 المُسلسَلة كاملةً. |
| `qr` | string (base64) | رمز QR بترميز TLV — الوسوم 1..6 (بدون الوسمين 7/8 المخزّنين). |
| `status` | string enum منطقي | حالة دورة الحياة: `GENERATED` أو `REPORTED`. |
| `zatcaUuid` | string \| null | المعرّف الذي تُعيده الهيئة بعد الإبلاغ الناجح (من `body.uuid`). |
| `reportedAt` | datetime \| null | لحظة الإبلاغ الناجح للهيئة. |
| `createdAt` | datetime | لحظة التوليد. |

**ملاحظة على `updatedAt`:** جدول Prisma `ZatcaInvoice` يحمل `createdAt` فقط **بلا** `updatedAt`، لذا `const UPDATED_AT = null;` في النموذج — يُعطَّل عمود التحديث ويُبقى تتبّع الإنشاء فقط.

**الحقول القابلة للتعبئة (`$fillable`):** `orderId, orgId, icv, uuid, pih, hash, xml, qr, status, zatcaUuid, reportedAt`.

**التحويلات (`$casts`):** `icv => integer`, `reportedAt => datetime`, `createdAt => datetime`.

**العلاقات:** `order()` — `belongsTo(Order::class, 'orderId')`.

### آلة حالات الفاتورة

```
   [POST /orders/{id}/zatca-invoice]
              │  (توليد idempotent)
              ▼
        ┌───────────┐
        │ GENERATED │  ← الحالة الابتدائية عند الإنشاء
        └───────────┘
              │  [POST /orders/{id}/zatca-report]
              │  إذا نجح الإبلاغ (result.ok)
              ▼
        ┌───────────┐
        │ REPORTED  │  + zatcaUuid + reportedAt = now()
        └───────────┘
```

- لا يوجد انتقال عكسي: بمجرد `REPORTED` لا يعود إلى `GENERATED`.
- إذا فشل الإبلاغ تبقى الفاتورة `GENERATED` ويُعاد الخطأ (502) دون تغيير الحالة.
- **لا توجد حالة CLEARED مخزّنة:** رغم أن `ZatcaClient::clearanceSingle()` (مسار B2B) مُنفّذ، إلا أن مسار المغاسل يستخدم `reportSingle` فقط، فالحالة المخزّنة الوحيدة بعد الإصدار هي `REPORTED`.

---

## 3. المرحلة 1 (QR الفوري)

المسؤول: `App\Support\Zatca` (ترميز TLV) و`ZatcaController::invoice` (بناء بيانات الفاتورة).

### 3.1 ترميز TLV والوسوم 1–5

كل حقل إلزامي يُرمَّز كسجل **TLV** (Tag-Length-Value):

```
[بايت الوسم][بايت الطول][بايت قيمة القيمة …]
```

- **الطول بايت واحد** فقط (المرحلة 1)، لذا كل قيمة **مقطوعة دفاعياً عند 255 بايت UTF-8** عبر `substr($value, 0, 255)` — لو تجاوزت لالتفّ الطول وأفسد الـ QR بصمت.
- `substr`/`strlen` تعملان على البايتات في PHP، مطابقةً لـ `Buffer.subarray(0, 255)` في JS.
- الناتج النهائي هو تسلسل السجلات مُرمَّزاً **base64**.

**الوسوم الخمسة:**

| الوسم | المحتوى | المصدر في `ZatcaController` |
| --- | --- | --- |
| 1 | اسم البائع (seller name) | `$org->name` |
| 2 | الرقم الضريبي (VAT number) | `$org->vatNumber` (سلسلة فارغة إن غاب) |
| 3 | الطابع الزمني ISO-8601 | `createdAt` بتوقيت UTC + مللي ثانية + `Z` |
| 4 | إجمالي الفاتورة شامل الضريبة | `$grandTotal` |
| 5 | إجمالي الضريبة | `$vatTotal` |

### 3.2 التنسيق

- **المال:** `number_format($n, 2, '.', '')` — منزلتان عشريتان، نقطة فاصلة، بلا فاصل آلاف. مطابق لـ JS `Number.toFixed(2)`.
- **الطابع الزمني:** `->clone()->utc()->format('Y-m-d\TH:i:s.v\Z')` — مطابق لـ JS `Date.toISOString()`. إن غاب `createdAt` يُستخدم `now()`.

### 3.3 بيانات الفاتورة (endpoint المرحلة 1)

`GET /orders/{id}/invoice` يُعيد كائن فاتورة ضريبية كاملاً — **غير مخزّن**، يُبنى في كل استدعاء:

- **البائع (seller):** الاسم، الرقم الضريبي، رقم السجل التجاري، العنوان — من المنشأة.
- **المشتري (buyer):** اسم العميل وهاتفه (من علاقة `customer`).
- **البنود (items):** لكل بند: الاسم (اسم الخدمة + نوع القطعة `garmentType` بين قوسين إن وُجد)، نوع الخدمة، الكمية، سعر الوحدة، إجمالي البند. أسماء أنواع القطع تُحلّ باستعلام واحد (`whereIn` على المعرّفات).
- **نسبة الضريبة:** `$order->taxRate` المُلتقَط وقت الطلب إن وُجد، وإلا `$org->taxRate` وإلا 15% افتراضياً.
- **الإجماليات:** `subtotal`, `discountTotal`, `vatTotal (taxTotal)`, `grandTotal` — كلها مُقرَّبة لمنزلتين.
- **ملخّص التسوية:** `paymentStatus`, `paidTotal`, `remaining = grandTotal - paidTotal`, وقائمة المدفوعات (الطريقة، المبلغ، المرجع) مرتّبة زمنياً.
- **`qr`:** ناتج `Zatca::qrPayload(...)`.
- **العملة:** `$org->defaultCurrency ?? 'SAR'`.

**العزل:** يُبحَث عن الطلب داخل مجموعة فروع المستأجر (`whereIn('branchId', $branchIds)`) — 404 إن لم يكن مملوكاً. ثم يُتحقَّق من وجود المنشأة (404 إن غابت).

---

## 4. المرحلة 2 (UBL، سلسلة ICV/PIH، التوليد idempotent)

المسؤول: `App\Support\ZatcaPhase2` (بناء XML + الهاش + QR) و`ZatcaPhase2Controller::store/show`.

### 4.1 سلسلة الفواتير (ICV + PIH + UUID)

كل فاتورة مرتبطة بالتي قبلها لتكوين سلسلة غير قابلة للتلاعب:

- **ICV** — عدّاد متسلسل **لكل منشأة**. الفاتورة الجديدة تأخذ `((آخر ICV) ?? 0) + 1`. مقيّد بفهرس فريد مركّب `[orgId, icv]`.
- **PIH** — هاش الفاتورة السابقة (`$last->hash`). للفاتورة الأولى في المنشأة = **`GENESIS_PIH`**.
- **UUID** — معرّف فريد لكل فاتورة (`Str::uuid()`).

**قيمة GENESIS_PIH** (ثابت في `ZatcaPhase2`):
```
NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==
```
وهي base64 لصيغة hex من SHA-256 للسلسلة `"0"` — القيمة التي تعرّفها الهيئة للفاتورة الأولى. مطابقة لـ `ZATCA_GENESIS_PIH` في `zatca.ts`.

### 4.2 QR المرحلة 2 (الوسم 6)

`ZatcaPhase2::qrPayloadV2(...)`:
1. يبني الوسوم 1..5 عبر `Zatca::qrPayload(...)` (إعادة استخدام كامل لمنطق TLV، بلا تكرار)،
2. يفكّ base64 للحصول على البايتات الخام،
3. يُلحِق **الوسم 6 = هاش الفاتورة** (base64 XML hash)،
4. يُعيد الترميز base64.

الوسمان 7 و8 (التوقيع والمفتاح العام) يُلحقان لاحقاً بعد التسجيل عبر `appendSignatureTags` (انظر §6).

### 4.3 هاش الفاتورة

`ZatcaPhase2::sha256Base64($input)` = `base64_encode(hash('sha256', $input, true))`.

- يغذّي الوسم 6 ويصبح PIH للفاتورة التالية.
- **تحذير موثّق في الكود:** الاعتماد الكامل لدى الهيئة يهشّم XML بعد **C14N canonicalization**؛ هنا يُهشّم الـ XML المُسلسَل مباشرةً — آلية السلسلة صحيحة، لكن الـ canonicalization معلّق حتى التسجيل (انظر §10).

### 4.4 بنية UBL 2.1

`ZatcaPhase2::buildInvoiceXml($inp)` يبني فاتورة مبسّطة (InvoiceTypeCode `388`، الاسم `0200000`، الملف `reporting:1.0`). أبرز عناصرها:

- `ProfileID = reporting:1.0`، `ID = orderNo`، `UUID`، `IssueDate`/`IssueTime` (مشتقّة من الطابع الزمني UTC بمللي ثانية).
- `InvoiceTypeCode name="0200000">388`، `DocumentCurrencyCode`/`TaxCurrencyCode`.
- **مرجعان إضافيان:** `AdditionalDocumentReference` بمعرّف `ICV` (يحمل العدّاد)، وآخر بمعرّف `PIH` (يحمل هاش الفاتورة السابقة كـ `EmbeddedDocumentBinaryObject mimeCode="text/plain"`).
- `AccountingSupplierParty`: الرقم الضريبي، الاسم القانوني، والعنوان (إن وُجد).
- `AccountingCustomerParty`: اسم العميل (قد يكون فارغاً).
- **الخصم كـ ALLOWANCE** (`ChargeIndicator=false`, السبب "خصم") إن كان `discountTotal > 0`.
- **الرسوم الإضافية كـ CHARGE** (`ChargeIndicator=true`, السبب "خدمة مستعجلة"): تُحسَب كفجوة بين الأساس الخاضع للضريبة ومجموع البنود الخام: `chargeTotal = max(0, round((taxableTotal + discountTotal - linesSum) * 100) / 100)`. تُبقِي أصافي البنود خاماً وتُوازن الإجماليات.
- `TaxTotal` مع `TaxSubtotal` (المبلغ الخاضع، مبلغ الضريبة، فئة الضريبة `S` بالنسبة).
- `LegalMonetaryTotal`: `LineExtensionAmount` (مجموع البنود)، `TaxExclusiveAmount`، `TaxInclusiveAmount`، `AllowanceTotalAmount`، `ChargeTotalAmount`، `PayableAmount`.
- `InvoiceLine` لكل بند: المعرّف، الكمية (`unitCode="PCE"`)، صافي البند، ضريبة البند (النسبة على صافي البند)، الصنف مع `ClassifiedTaxCategory`، والسعر.

**التنسيق الداخلي:**
- `n2()` — منزلتان عشريتان (مثل toFixed(2)).
- `num()` — كمية بلا أصفار زائدة (عدد صحيح إن أمكن، وإلا 6 منازل مع تشذيب).
- `esc()` — **تهرِيب XML صارم:** يُهرِّب `& < > "`، **و** يُزيل محارف التحكّم C0 (عدا TAB/LF/CR) وDEL وكتلة C1 عبر regex. السبب الموثّق: اسم العميل نص حر؛ محرف تحكّم شارد (مثل 0x08 من ماسح باركود) يجعل المستند غير صالح ويُرفَض من الهيئة **بعد** أن يكون قد وُقِّع ورُبِط في سلسلة ICV/PIH — أسوأ لحظة ممكنة. XML 1.0 لا يسمح إلا بـ TAB/LF/CR وما فوق 0x20، فالباقي يُحذَف (لا يُستبدَل، إذ يُغيّر معنى الاسم).

### 4.5 التوليد idempotent ومعالجة سباق ICV

`ZatcaPhase2Controller::store` (`POST /orders/{id}/zatca-invoice`):

1. **الصلاحية:** `requirePermission($request, 'orders.manage')`.
2. جلب الطلب داخل فروع المستأجر (404 إن لم يُملَك).
3. **فحص idempotent:** إن وُجدت فاتورة للطلب (`orderId` فريد) تُعاد كما هي (200) دون إعادة توليد.
4. **شرط التسجيل الضريبي:** إن غاب `org->vatNumber` → 422 ("المنشأة غير مسجّلة ضريبياً").
5. حساب `taxableTotal = subtotal - discountTotal`، `vatRate`، العملة، البنود.
6. **حلقة إعادة المحاولة (حتى 8 مرات) لمعالجة سباق ICV:**
   - كل محاولة تُعيد قراءة أحدث ICV (`orderBy('icv','desc')->first()`) وتحسب `icv = آخر + 1` و`pih = آخر hash ?? GENESIS_PIH`.
   - تبني XML → تحسب الهاش → تبني QR → تحاول `create`.
   - عند `QueryException` برمز **`23505`** (unique_violation):
     - إن كان تصادم `orderId` (استدعاء آخر أنشأها) → تُعاد الفاتورة القائمة (200).
     - وإلا فهو سباق `[orgId, icv]` → تُكرَّر الحلقة لإعادة حساب الـ ICV التالي (تصحيح ذاتي).
   - أي خطأ آخر (رمز ≠ 23505) يُعاد رميه.
7. عند النجاح تُخزَّن الفاتورة بحالة `GENERATED` وتُعاد (201).
8. **حماية من الاستنزاف:** إن نفدت المحاولات الثماني تحت ضغط متواصل → يُبحَث مجدداً عن الفاتورة، فإن وُجدت تُعاد (200)، وإلا 503 ("تعذّر إصدار الفاتورة، حاول مرة أخرى"). استدعاء لاحق يشفي نفسه.

`GET /orders/{id}/zatca-invoice` (`show`): يُعيد الفاتورة المخزّنة (مقيّدة بـ `orgId`) أو 404. **لا يوجد فحص صلاحية `orders.manage` على القراءة** — القراءة متاحة لأي توكن staff للمستأجر.

**الحمولة المُسلسَلة (`serialize`):** `id, orderId, orgId, icv, uuid, pih, hash, status, zatcaUuid, qr, xml, reportedAt, createdAt`.

---

## 5. Onboarding (CSR، compliance، production، status)

المسؤول: `ZatcaCsr` (توليد المفاتيح والـ CSR)، `ZatcaClient` (HTTP)، `ZatcaOnboardingController` (API)، و`ZatcaOnboard` (CLI).

### 5.1 توليد CSR على منحنى secp256k1

الهيئة تتطلّب زوج مفاتيح **EC على منحنى secp256k1** و CSR يحمل امتدادات X.509 مخصّصة لا يستطيع `ext-openssl` في PHP إصدارها، لذا **يُستدعى ثنائي `openssl` عبر Symfony Process** بملف إعداد مُولَّد (`openssl.cnf`). الامتدادات:

- **OID `1.3.6.1.4.1.311.20.2`** = اسم قالب البيئة (`certificateTemplateName`).
- **subjectAltName بنوع dirName** يحمل: EGS SerialNumber (`1-<solution>|2-<model>|3-<serial>`)، الرقم الضريبي (UID)، نوع الفاتورة (title)، العنوان المسجّل، وفئة النشاط.

خطوات `ZatcaCsr::generate($org, $force)`:
1. **idempotent افتراضياً:** إن وُجد زوج مفاتيح (`hasKeypair`) ولم يُطلَب `force` → يُعاد الـ CSR الموجود دون إعادة توليد.
2. بناء الـ subject من المنشأة (`subjectFrom`).
3. كتابة ملف الإعداد (`writeConfig`).
4. توليد المفتاح الخاص: `openssl ecparam -name secp256k1 -genkey -noout` ثم `chmod 0600`.
5. توليد الـ CSR: `openssl req -new -sha256 -key … -config … -out …`.
6. إعادة `csrPem`, `csrBase64`, مساري المفتاح والـ CSR، والـ subject.

`generateDevCert($days=365)`: يُنشئ شهادة **موقّعة ذاتياً** من المفتاح القائم (`openssl req -x509`) لتجربة التوقيع والوسمين 7/8 **محلياً قبل** وجود CSID حقيقي. **شهادة تطوير فقط لا تقبلها الهيئة أبداً.**

### 5.2 تعقيم مدخلات المستأجر ضد الحقن في openssl.cnf

**نقطة أمنية حرجة موثّقة في الكود:** كل قيمة من المستأجر يجب أن تمرّ عبر `ascii()` **قبل** إدراجها في `openssl.cnf`، لأن صيغة هذا الملف تُوسّع `$ENV::NAME`. حقل غير مُعقّم (مثل `vatNumber`/`crNumber` وهما نص حر قابل للتعديل من المستأجر) قد يسمح للمستأجر بقراءة متغيّرات بيئة الخادم — `DB_PASSWORD`, `APP_KEY` — مباشرةً من الـ CSR المُعاد.

`ascii($value)` يقوم بـ:
1. تحويل من UTF-8 إلى ASCII//TRANSLIT (نقحرة النص العربي).
2. إزالة أي محرف خارج المدى `\x20-\x7E`.
3. **استبدال `$` والباك-تِك `` ` `` وعلامات الاقتباس والأسطر الجديدة بمسافة** — وهذا بالضبط ما يجعل الحقن مستحيلاً.
4. إن أصبحت القيمة فارغة تُعاد `'NA'`.

الحقول المُعقّمة: `organizationName (name)`, `organizationUnit (crNumber/vatNumber)`, جزء serial من `branchId/crNumber`, `vatNumber`, `registeredAddress`. الحقول الثابتة (من config) لا تُعقّم لأنها ليست من المستأجر.

### 5.3 compliance CSID عبر OTP

`POST /zatca/onboarding/compliance` (body: `{ otp }`, طول 4–12):
1. تفويض الدور، جلب المنشأة، التحقق من وجود CSR (`hasKeypair`، وإلا 422).
2. قراءة الـ CSR وترميزه base64.
3. استدعاء `ZatcaClient::complianceCsid($csrBase64, $otp)` → `POST {base}/compliance` بترويسة `OTP` و`{ csr }`.
4. عند النجاح تُخزَّن `complianceCsid { binarySecurityToken, secret, requestID }` و`compliedAt` عبر `ZatcaStore::merge`.
5. **لا يُعاد `body` أبداً:** لأنه يحمل `binarySecurityToken + secret` وهما بيانات المصادقة (Basic-auth) لكل إبلاغ للهيئة. إرجاعهما يكشفهما في devtools وسجلات البروكسي وتاريخ المتصفح. يُعاد فقط `ok`, `status`, `requestID` (غير حسّاس)، و`error`. رمز الاستجابة 200 عند النجاح، 422 عند الفشل.

**بدون OTP صالح** تُعيد البوابة 400/401 — سلوك متوقّع يُبلَّغ بصدق دون تزييف. الـ OTP يُولّده المكلّف من بوابة Fatoora (صالح ~ساعة).

### 5.4 production CSID

**عبر CLI فقط** (`ZatcaOnboard`، الخطوة 4) — لا يوجد endpoint API له. بعد compliance CSID الناجح:
- `ZatcaClient::productionCsid($csid, $complianceRequestId)` → `POST {base}/production/csids` بـ `{ compliance_request_id }` مع Basic-auth بشهادة الامتثال.
- عند النجاح تُخزَّن `productionCsid { binarySecurityToken, secret, requestID }` و`onboardedAt`.

**ملاحظة:** خطوة **فحص الامتثال** (`complianceCheckInvoice` → `POST /compliance/invoices` بعيّنات موقّعة، الخطوة 3) **مُنفّذة في `ZatcaClient` لكنها غير مستدعاة** في أي مسار (لا CLI ولا controller).

### 5.5 status

`GET /zatca/status` (يُعيد 200 دائماً ليُظهِر اللوحة):
- `env`, `baseUrl`, `portalUrl`, `vatNumber`.
- `hasCsr` (`hasKeypair`)، `onboarded` (`isOnboarded`)، `hasProductionCsid`.
- `certFingerprint` — بصمة SHA-256 للشهادة الموقِّعة إن وُجدت.
- `compliedAt`.

---

## 6. التوقيع والمفاتيح والتخزين

### 6.1 التوقيع (ECDSA/SHA-256، الوسمان 7 و8)

المسؤول: `App\Support\ZatcaSigner`.

- **`signInvoiceHash($invoiceHashBase64, $privateKeyPem)`:** يقرأ المفتاح الخاص، يفكّ base64 لهاش الفاتورة (بايتات مُلخّص SHA-256)، ثم `openssl_sign(..., OPENSSL_ALGO_SHA256)`. يُعيد بايتات توقيع DER الخام. **الوسم 7** يحملها.
- **`publicKeyBytes($certPem)`:** يقرأ الشهادة، يستخرج المفتاح العام، ويحوّله من PEM إلى DER (`pemToDer`). **الوسم 8** يحمله.
- **`stamp(...)`:** يوقّع + يستخرج المفتاح العام + يُلحق الوسمين 7/8 بـ QR الوسوم 1..6 عبر `ZatcaPhase2::appendSignatureTags`. يُعيد `{ qr, signatureBase64, publicKeyBase64 }`.

**عقد الحماية:** عندما لا تتوفّر شهادة/مفتاح، لا تُستدعى هذه الطبقة إطلاقاً ويُصدَر QR الوسوم 1..6 دون تغيير — الفواتير القائمة لا تنكسر.

**نطاق موثّق:** ينتج توقيع ECDSA/SHA-256 حقيقياً على منحنى secp256k1 + المفتاح العام (وهو بالضبط ما يحمله الوسمان 7/8). أما كتلة XAdES `UBLExtensions <Signature>` داخل XML (اللازمة لحمولة إبلاغ مقبولة لدى الهيئة) فهي TODO موثّق، غير قابلة للتحقيق بدون CSID أصلاً (محجوبة بالـ OTP) — انظر §10.

### 6.2 المفاتيح والتخزين

المسؤول: `App\Support\ZatcaStore` (تحت سكيما Prisma المجمّدة — لا جدول/عمود جديد).

**التقسيم:**
- **حالة التسجيل** (compliance/production CSID، requestID، الطوابع) → JSON في جدول `Setting` بمفتاح `zatca.state:{orgId}` و`branchId = null`.
- **المفاتيح الخاصة، الـ CSR، الشهادات** → على نظام الملفات تحت `storage/app/zatca/{orgId}/` (لا تنتمي لقاعدة بيانات مشتركة).

**أمن الأسرار:**
- المجلد يُنشَأ بصلاحية **`0700`**، والمفتاح الخاص بصلاحية **`0600`**.
- الأسرار (`complianceCsid.secret`, `productionCsid.secret`) **مشفّرة** عند التخزين عبر `SecretValue::encrypt` وتُفكّ عبر `SecretValue::decrypt` عند القراءة — لا تُخزَّن كنص صريح في `Setting`.

**المسارات:** `privateKeyPath` (`ec-private-key.pem`)، `csrPath` (`taxpayer.csr`)، `csrConfigPath` (`csr-config.cnf`)، `devCertPath` (`dev-cert.pem`)، وشهادة CSID (`csid-cert.pem` تُبنى من `binarySecurityToken` بإضافة غلاف PEM).

**دوال الحالة:**
- `isOnboarded()` — صحيح متى وُجد compliance CSID كامل.
- `activeCsid()` — يُفضّل production CSID، وإلا compliance، وإلا null.
- `signingCertPath()` — شهادة CSID إن وُجدت، وإلا شهادة التطوير، وإلا null.

---

## 7. الإبلاغ للهيئة (reporting/clearance)

المسؤول: `ZatcaClient` (النقل) و`ZatcaOnboardingController::report` (التنسيق).

### 7.1 عميل ZatcaClient (تدفّق Fatoora)

كل دالة تُعيد مصفوفة موحّدة `{ status, ok, body, error }` **دون رمي استثناء أبداً** — فشل النقل (DNS/TLS/timeout) يُلتقَط كـ `ok=false` مع نص الخطأ.

| الدالة | النقطة النهائية | الغرض |
| --- | --- | --- |
| `complianceCsid` | `POST /compliance` | ترويسة `OTP` + `{ csr }` → CSID الامتثال |
| `complianceCheckInvoice` | `POST /compliance/invoices` | عيّنات موقّعة (Basic-auth) — *غير مستدعاة* |
| `productionCsid` | `POST /production/csids` | `{ compliance_request_id }` → CSID الإنتاج |
| **`reportSingle`** | `POST /invoices/reporting/single` | **B2C مبسّطة** — ترويسة `Clearance-Status: 0` → متوقّع `reportingStatus: "REPORTED"` |
| `clearanceSingle` | `POST /invoices/clearance/single` | **B2B قياسية** — ترويسة `Clearance-Status: 1` → متوقّع `clearanceStatus: "CLEARED"` (*مُنفّذة، غير مستخدمة للمغاسل*) |

- **المصادقة:** Basic-auth حيث المستخدم = `binarySecurityToken` وكلمة المرور = `secret`.
- **الترويسات:** `Accept`/`Content-Type: application/json`, `Accept-Version` (افتراضي V2), `Accept-Language: en`.
- **النقل:** `Http::baseUrl`, timeout (افتراضي 15 ثانية), إعادة محاولة واحدة (`retry_times + 1`) بفاصل 300 مللي، `throw: false`.

### 7.2 تنسيق الإبلاغ

`POST /orders/{id}/zatca-report`:
1. تفويض الدور، جلب المنشأة.
2. **شرط التهيئة:** إن لم تكن المنشأة `isOnboarded()` → 422 ("المنشأة غير مُهيّأة لدى ZATCA — أكمل التسجيل بالرمز OTP أولاً").
3. **إعادة استخدام المولّد idempotent:** إن لم توجد فاتورة للطلب، يُستدعى `ZatcaPhase2Controller::store` لتوليدها (لا تكرار لمنطق البناء/الهاش/السلسلة). يتحقّق من ملكية المستأجر ذاتياً.
4. **الختم:** إن وُجدت شهادة موقِّعة ومفتاح خاص، يُبنى QR الوسوم 1..6 (بطوابع لحظية) ثم يُختَم عبر `ZatcaSigner::stamp` لإنتاج QR بالوسوم 1..8. وإلا يُستخدم `invoice->qr` كما هو.
5. **الإرسال:** `reportSingle($csid, { invoiceHash, uuid, invoice: base64(xml) })`.
6. **عند النجاح:** تحديث الفاتورة إلى `REPORTED` + `zatcaUuid` (من `body.uuid`) + `reportedAt = now()`.
7. **الاستجابة:** `{ ok, status, reportingStatus, body, error, qr: signedQr, stamp }`. رمز 200 عند النجاح، **502** عند الفشل.

---

## 8. قواعد البيزنس (مرقّمة)

1. **إضافة فقط على السكيما:** حالة التسجيل تُخزَّن في `Setting` كـ JSON، والمفاتيح/الشهادات على نظام الملفات — لا جدول ولا عمود جديد (باستثناء `ZatcaInvoice` نفسه المُعرّف في Prisma).
2. **QR المرحلة 1 غير مخزّن** — يُبنى في كل استدعاء لـ `GET /orders/{id}/invoice`.
3. **كل قيمة TLV مقطوعة عند 255 بايت** — طول بايت واحد في المرحلة 1.
4. **تنسيق المال دائماً بمنزلتين ونقطة فاصلة** بلا فاصل آلاف (`toFixed(2)`).
5. **الطابع الزمني دائماً UTC + مللي ثانية + Z** (`toISOString`).
6. **فاتورة واحدة لكل طلب** — `orderId` فريد؛ الاستدعاء الثاني يُعيد الصف نفسه دون تغيير (idempotent).
7. **ICV متسلسل لكل منشأة** يبدأ من 1، مقيّد بفهرس فريد `[orgId, icv]`.
8. **PIH = هاش الفاتورة السابقة**؛ الأولى في المنشأة تأخذ `GENESIS_PIH`.
9. **سباق ICV يُعالَج بإعادة المحاولة** حتى 8 مرات على رمز postgres `23505`؛ تصادم `orderId` يُعيد الفاتورة القائمة، وسباق ICV يُعيد الحساب.
10. **لا فاتورة ضريبية بلا تسجيل ضريبي** — غياب `org->vatNumber` → 422 في المرحلة 2 والتسجيل والإبلاغ.
11. **الفاتورة لا تُبلَّغ قبل التهيئة** — `isOnboarded()` شرط لـ `/zatca-report` (وإلا 422).
12. **الأسرار لا تُعاد أبداً في الاستجابات** — `binarySecurityToken/secret` تُخزَّن مشفّرة وتُحجَب؛ يُعاد `requestID` فقط.
13. **تعقيم كل مدخلات المستأجر قبل openssl.cnf** — `ascii()` يمنع حقن `$ENV::` وتسريب متغيّرات البيئة.
14. **الأسرار مشفّرة على القرص** (`SecretValue`) والمفاتيح بصلاحية 0600 والمجلدات 0700.
15. **تهرِيب XML يزيل محارف التحكّم** إضافةً لتهريب `& < > "` — منعاً لرفض الهيئة بعد التوقيع والربط.
16. **الوسمان 7/8 يُلحقان فقط عند وجود شهادة**؛ وإلا يُصدَر QR الوسوم 1..6 دون كسر.
17. **مسار المغاسل = reporting (B2C مبسّطة)**؛ clearance (B2B) مُنفّذ لكن غير مستخدم.
18. **الهاش على XML المُسلسَل** حالياً؛ الاعتماد الكامل يتطلّب C14N (فجوة موثّقة).
19. **عميل البوابة لا يرمي استثناءً** — كل فشل يُلتقَط كـ `{ ok:false, error }` ويُبلَّغ بصدق.
20. **إعادة الرسوم كـ CHARGE والخصم كـ ALLOWANCE** على مستوى المستند لإبقاء أصافي البنود خاماً وموازنة الإجماليات.

---

## 9. الأدوار والصلاحيات + قائمة العمليات الكاملة

جميع المسارات تحت `middleware('auth.api')`. العزل عبر `ResolvesTenant`: استدعاء `orgId()`/`branchId()`/`branchIds()` يستدعي داخلياً **`assertStaff()`** الذي يرفض توكنات العملاء/الموردين/البوابة (تحمل orgId لكن `kind ≠ staff`) — فكل النقاط ترفض التوكنات غير الموظّفة ضمنياً.

| العملية | المسار | الطريقة | فحص الصلاحية | الرمز عند الرفض |
| --- | --- | --- | --- | --- |
| فاتورة المرحلة 1 (QR فوري) | `/orders/{id}/invoice` | GET | staff فقط (ضمنياً عبر `branchIds`) — لا بوابة دور صريحة | 403/404 |
| توليد فاتورة المرحلة 2 | `/orders/{id}/zatca-invoice` | POST | **`requirePermission('orders.manage')`** | 403 |
| عرض فاتورة المرحلة 2 | `/orders/{id}/zatca-invoice` | GET | staff فقط (مقيّد بـ orgId) — **لا فحص orders.manage** | 404 |
| توليد/إعادة CSR | `/zatca/onboarding/csr` | POST | `SUPER_ADMIN` أو `BRANCH_MANAGER` | 403 |
| تبادل OTP بـ compliance CSID | `/zatca/onboarding/compliance` | POST | `SUPER_ADMIN` أو `BRANCH_MANAGER` | 403 |
| حالة التسجيل | `/zatca/status` | GET | `SUPER_ADMIN` أو `BRANCH_MANAGER` | 403 |
| الإبلاغ للهيئة | `/orders/{id}/zatca-report` | POST | `SUPER_ADMIN` أو `BRANCH_MANAGER` | 403 |

**بوابة أدوار التسجيل** (`authorizeRole`): `ALLOWED_ROLES = ['SUPER_ADMIN', 'BRANCH_MANAGER']` — تقرأ `claims['role']` وترفض بـ 403 ("صلاحية غير كافية").

### الأمر المجدول والـ cron

- **الأمر (CLI):** `php artisan zatca:onboard` — `App\Console\Commands\ZatcaOnboard`. الخيارات: `--org` (افتراضياً أول منشأة مسجّلة ضريبياً)، `--otp`، `--force`، `--dev-cert`. يمرّ بالخطوات CSR → compliance CSID → production CSID، ويُخزّن كل نتيجة، ويُبلّغ بصدق ما تُعيده البوابة. بدون `--otp` يتوقّف بعد CSR. **ليس مجدولاً تلقائياً في `schedule` — يُشغّل يدوياً.**
- **الـ cron في `zatca-p2.php`:** المسار `POST /cron/auto-status` → `CronController::autoStatus` **ليس تكامل ZATCA** — هو مشغّل أتمتة حالات الطلبات، مُشارَك في نفس الملف فقط. **غير مصادَق بالمستأجر**؛ محمي بسرّ مشترك (ترويسة `X-Cron-Secret` أو `?secret=`) مع `throttle:30,1`.

---

## 10. الفجوات المعروفة (XAdES، C14N)

1. **كتلة XAdES `UBLExtensions <Signature>` غير مُدرَجة في XML:** التوقيع الكامل داخل المستند (اللازم لحمولة إبلاغ **مقبولة** لدى الهيئة) لم يُدرَج بعد. حالياً `ZatcaSigner` ينتج توقيع ECDSA + مفتاحاً عاماً حقيقيين للوسمين 7/8 فقط. هذه الفجوة **غير قابلة للتحقيق بدون CSID أصلاً** (محجوبة بالـ OTP الذي يُولّده المكلّف). موثّقة في تعليقات `ZatcaSigner` و`ZATCA_SETUP.md`.

2. **الـ C14N canonicalization غير مطبّقة:** هاش الفاتورة يُحسَب حالياً على **XML المُسلسَل مباشرةً**؛ اعتماد الهيئة الكامل يهشّم **XML بعد canonicalization (C14N)**. آلية السلسلة (ICV/PIH) صحيحة، لكن قيمة الهاش نفسها ستختلف عمّا تتوقّعه الهيئة حتى تُطبَّق الـ canonicalization. موثّقة في `ZatcaPhase2::sha256Base64` و`ZATCA_SETUP.md`.

### فجوات وحالات خاصة إضافية

3. **خطوة فحص الامتثال غير مستدعاة:** `complianceCheckInvoice` (`POST /compliance/invoices` بعيّنات موقّعة، الخطوة 3 في تدفّق Fatoora) مُنفّذة في `ZatcaClient` لكن **لا يستدعيها** أي CLI أو controller — تنتقل الأتمتة من compliance CSID مباشرةً إلى production CSID.

4. **production CSID عبر CLI فقط:** لا يوجد endpoint API لشهادة الإنتاج — تُنجَز فقط عبر `zatca:onboard`. الـ API يتوقّف عند compliance CSID، والـ `report` يستخدم `activeCsid()` (production إن وُجد وإلا compliance).

5. **قراءة فاتورة المرحلة 2 بلا بوابة `orders.manage`:** `GET /orders/{id}/zatca-invoice` مقيّد بـ orgId + staff فقط، بينما التوليد يتطلّب `orders.manage`. أي موظّف بالمستأجر يقرأ الفاتورة المخزّنة كاملةً (بما فيها XML).

6. **QR المرحلة 1 بلا بوابة دور صريحة:** `GET /orders/{id}/invoice` يعتمد على `assertStaff` الضمني فقط (لا `requireManager`/`requirePermission`) — أي توكن staff للمستأجر يصل إلى فاتورة المرحلة 1.

7. **الطابع الزمني للختم لحظي وليس مثبَّتاً:** في `report`، عند إعادة بناء QR للختم، يُشتَق الطابع من `invoice->createdAt` إن وُجد وإلا `now()` — لكن الوسوم 1..6 المخزّنة في `invoice->qr` بُنيت أصلاً بطابع الطلب، فقد يختلف الطابع بين QR المخزّن وQR المختوم إن غاب `createdAt`.

8. **اعتماد على وجود ثنائي `openssl` النظامي:** توليد المفاتيح والـ CSR يستدعي `openssl` عبر Process (لأن `ext-openssl` لا يصدر الامتدادات المطلوبة)؛ غياب الثنائي أو اختلاف نسخته يفشل التسجيل بـ `RuntimeException`.

9. **البيئة الافتراضية sandbox:** `ZATCA_ENV=sandbox` والـ base URL يشير لبوابة المطوّرين؛ الانتقال للإنتاج يتطلّب تغيير `ZATCA_BASE_URL` وقالب CSR (`ZATCA_CSR_TEMPLATE`: sandbox=`TSTZATCA-Code-Signing`, simulation=`PREZATCA-Code-Signing`, production=`ZATCA-Code-Signing`).
