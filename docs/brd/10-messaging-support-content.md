# BRD 10 — المراسلة والدعم والمحتوى

> يفترض هذا الملف أنك قرأت `00-overview-architecture.md` (بنية الـ API، توكن HMAC، سمة `ResolvesTenant`، نموذج `PrismaModel`، جدول `Setting` كـ key/value، بوابات الأدوار). هنا نغطي كل ما يخص: رسائل واتساب/SMS، رموز OTP، البريد، الإشعارات والتنبيهات، الأتمتة الخلفية، الدعم و CRM، المجتمع (المنتدى والمدوّنة)، منشورات السوشال، إعلانات المنشأة، والتسويق بالعمولة.

---

## 1. نظرة عامة

هذه الوحدة تجمع كل قنوات التواصل والمحتوى في النظام، وتنقسم إلى ثلاث دوائر متمايزة من حيث الملكية والصلاحية:

1. **قنوات المنشأة → عملائها**: رسائل واتساب/SMS الآلية (طلب جاهز، فاتورة، OTP…)، وإعلانات المنشأة في بوابة العميل، وسجل الإشعارات الصادرة، والتنبيهات التشغيلية المشتقّة حيّاً.
2. **قنوات المنصة → المنشآت**: بريد الاشتراكات (ترحيب/فاتورة/تذكير/إيقاف)، الدعم الفني (تذاكر المنشأة)، إعلانات/إشعارات المنصة الداخلية.
3. **قنوات المنصة الداخلية والتسويقية**: المنتدى العالمي، المدوّنة العالمية، منشورات السوشال للمنصة نفسها، مراسلة المشرفين الداخلية (1:1 + قنوات)، نظام CRM والعملاء المحتملين (Leads)، وبرنامج التسويق بالعمولة (Affiliate) بواجهته المستقلة.

**البوابة المركزية** لكل رسالة واتساب/SMS في النظام هي `App\Services\Messaging\WaService::queue()` — لا يوجد مسار إرسال آخر. تمر كل رسالة عبر حارس (gate) بست طبقات، تُسجَّل في صف `WaMessage` (مصدر الحقيقة للحصص والإحصائيات)، ثم تُدفَع إلى طابور عبر `SendWaMessage` job.

**مبدأ «best-effort»**: الإرسال أثر جانبي لا يُفشِل العملية الأصلية أبداً. `queue()` لا يرمي استثناءً على حجب تجاري — يسجّل صفاً بحالة `BLOCKED` ويعيده؛ المزوّد لا يرمي على فشل تسليم عادي (عقد `MessagingProvider`)؛ استدعاءات `trigger()` من مسارات الطلبات كلها ملفوفة بـ `try/catch` مع `report($e)`.

---

## 2. المراسلة وواتساب (WaService، الحصص، المزوّد، الويبهوك، القوالب)

### 2.1 الأحداث والفئات (WaService)

**الأحداث (EVENTS)** — مفاتيح camelCase (بلا نقاط، آمنة للتحقق):
`orderCreated`, `orderReady`, `orderCompleted`, `otp`, `invoice`, `delivery`, `manual`, `test`.

**الفئات (CATEGORIES)** — تطابق تصنيف واتساب Cloud:
`MARKETING`, `UTILITY`, `AUTHENTICATION`, `SERVICE`.

**الفئة الافتراضية لكل حدث (EVENT_CATEGORY)**:
| الحدث | الفئة |
|------|------|
| orderCreated | UTILITY |
| orderReady | UTILITY |
| orderCompleted | UTILITY |
| otp | AUTHENTICATION |
| invoice | UTILITY |
| delivery | UTILITY |
| manual | MARKETING |
| test | SERVICE |

**القوالب الاحتياطية المدمجة (FALLBACK_TEMPLATES)** — نصوص عربية جاهزة تُستخدم حين لا يوجد قالب مخصّص: `orderCreated`, `orderReady`, `orderCompleted`, `otp`, `invoice`, `delivery`, `test` (لكل منها نص افتراضي بمتغيّرات `{name}` `{orderNo}` `{total}` `{org}` `{code}` `{status}`).

**مَن يُطلق الأحداث الآلية:**
- `orderCreated` → من `OrderCreator::…` و `PosController::store` (عند إنشاء الطلب).
- `orderReady` → من `OrderDetailController` (تحويل الحالة إلى READY) ومن `AutomationSweeper` عند الترقية التلقائية إلى READY.
- `orderCompleted` → من `OrderDetailController` (تحويل الحالة إلى DELIVERED).
- `otp` → من `PortalAuthController` و `PosOtpController` و `DriverAuthController` و `AffiliateAuthController` (عبر `queue()` مباشرة، ليس `trigger()`).
- `test` → من `WaController::test`.

### 2.2 وضع المرسِل (PLATFORM / CUSTOM)

لكل منشأة «وضع مرسِل» فعّال:
- **PLATFORM**: تُرسَل عبر اعتماد غسلة المركزي (`services.messaging` / Cloud API الخاص بالمنصة)، أو stub التسجيل (`LogProvider`) في التطوير.
- **CUSTOM**: تُرسَل عبر اعتماد المنشأة الخاص، المخزَّن في إعداد `messaging.config:{orgId}` (حقول `whatsapp.token` و `whatsapp.phoneId`، والتوكن مشفّر عبر `SecretValue`).

**قاعدة الحسم** (`senderMode()`): الوضع = `CUSTOM` فقط إذا كان `whatsapp.mode === 'CUSTOM'` **و** `token !== ''` **و** `phoneId !== ''`؛ خلاف ذلك `PLATFORM`. أي غياب لأحد العنصرين يُسقِط تلقائياً إلى PLATFORM.

**حل المزوّد** (`provider()`): لقناة WHATSAPP في وضع CUSTOM يُبنى `WhatsAppProvider` جديد بتوكن المنشأة و phoneId وقاعدة الـ API؛ غير ذلك يُرجَع المزوّد المربوط في الحاوية (`app(MessagingProvider::class)`) وهو مرسِل المنصة أو `LogProvider`.

### 2.3 الحارس (gate) — الطبقات الست بالترتيب

`gate(orgId, branchId, category, eventKey)` يُرجِع `null` عند السماح، أو نص الحجب بالعربي. **قاعدة خاصة قبل الطبقات**: إذا كان `orgId === null` (رسائل مستوى المنصة كـ OTP سائق) → يُسمح فوراً بلا أي فحص حصص. ثم بالترتيب الحرفي:

1. **مفتاح إيقاف المنصة العام** (`platformEnabled`): إن كان مطفأً يُحجب كل شيء **إلا** فئة `AUTHENTICATION` (لا تُحجب رموز الدخول أبداً). النص: «رسائل واتساب موقوفة مؤقتاً على مستوى المنصة».
2. **مفتاح المنشأة من إدارة المنصة** (`wa.limits:{orgId}.enabled`): إن كان `false` → «رسائل واتساب معطلة لهذه المنشأة من إدارة المنصة».
3. **قائمة الفئات المسموحة (allow-list من الأدمن)** (`limits.categories[category]`): إن كانت الفئة معطّلة → «فئة الرسائل {category} غير مفعّلة لهذه المنشأة».
4. **قائمة الأحداث المسموحة من الأدمن** (`limits.allowedEvents[eventKey]`): إن كان الحدث ممنوعاً → «هذا النوع من الرسائل غير مسموح به من إدارة المنصة».
5. **ساعات الهدوء (quiet hours)**: إن كانت مفعّلة والفئة ضمن `quietHoursCategories` (افتراضياً MARKETING فقط؛ OTP/UTILITY لا تتأهّل) والوقت الحالي (بتوقيت المنصة) داخل النافذة → يُسقَط النص: «خارج ساعات الإرسال المسموح بها (ساعات الهدوء)». النافذة تدعم التفاف منتصف الليل (مثال 22:00 → 07:00).
6. **إعدادات المنشأة نفسها**:
   - `orgConfig.enabled` (المفتاح الرئيسي للمنشأة): إن كان `false` → «الرسائل معطلة في إعدادات المنشأة».
   - `orgConfig.events[eventKey]`: إن كان الحدث موجوداً ومعطّلاً → «هذا الحدث معطل في إعدادات المنشأة».

ثم **حصص الاستهلاك** (بعد الطبقات الست، جزء من نفس الدالة):
7. **الحصة الشهرية للمنشأة** (`limits.monthlyLimit`، 0 = بلا حد): إن بلغ الاستهلاك الحد → «تم استهلاك حصة الرسائل الشهرية للمنشأة».
8. **الحصة الشهرية للفرع** (إن وُجد `branchId`): الحد = `limits.branchLimits[branchId]` أو حصة الفرع الافتراضية من سياسة المنصة (`branchMonthlyQuota`)؛ إن بلغ الاستهلاك → «تم استهلاك حصة الرسائل الشهرية للفرع».

### 2.4 الحصص الشهرية (منشأة + فرع)

- **مصدر العدّ**: `monthUsed(orgId, branchId?)` = عدد صفوف `WaMessage` بحالة ضمن `COUNTED_STATUSES` = `['QUEUED','SENT','DELIVERED','READ']`، من بداية الشهر الحالي. أي صف بحالة `QUEUED` يُحتسب فوراً (حتى قبل الإرسال الفعلي) — لذا العدّ + الإدراج يجب أن يكونا ذرّيين.
- **الافتراضات من سياسة المنصة** (`PlatformSettings::whatsapp()`): `orgMonthlyQuota = 1000`، `branchMonthlyQuota = 0` (بلا حد)، وكلاهما 0 = بلا حد. المنشأة بلا override صريح تَرِث هذه الأرقام.
- **تجاوزات لكل منشأة/فرع** تُخزَّن في `wa.limits:{orgId}` (يديرها أدمن المنصة): `monthlyLimit`, `branchLimits: {branchId: int}`, `categories`, `allowedEvents`, `enabled`.

### 2.5 الطابور مع القفل الإرشادي (advisory lock)

في `queue()`:
- **رسائل المنصة** (`orgId === null`): لا قفل — تمر مباشرة (بلا حصة org).
- **رسائل المنشأة**: تُغلَّف في `DB::transaction` مع `pg_advisory_xact_lock(hashtext('wa-quota:'.orgId))`. هذا يُسلسِل فحص-الحصة + الإدراج لكل منشأة، فلا يقرأ إرسالان متزامنان عدّاً تحت-الحد ويتجاوزان معاً الحصة المدفوعة. داخل القفل يُعاد استدعاء `gate()`؛ إن حجب → صف `BLOCKED`، وإلا → صف `QUEUED`.
- بعد نجاح المعاملة، إن كانت الحالة `QUEUED` تُدفَع `SendWaMessage::dispatch` عبر `DB::afterCommit` (لتُطلَق بعد تثبيت معاملة إنشاء الطلب المحيطة).

**رقم غير صالح**: إن فشل تطبيع الهاتف (`Phone::normalize`) يُسجَّل الصف فوراً `BLOCKED` بخطأ «رقم الجوال غير صالح» بلا قفل.

### 2.6 حل القالب بالأولوية (resolveTemplate)

الترتيب لحدثٍ معيّن `eventKey`:
1. `WaTemplate` نشط للمنشأة (`orgId` مطابق، `isActive=true`، الأحدث) —
2. وإلا `WaTemplate` نشط افتراضي للمنصة (`orgId = null`) —
3. وإلا نص القالب القديم في `messaging.config.templates` (خريطة legacy: `orderReady→orderReady`, `otp→otp`, `invoice→paymentLink`) إن كان غير فارغ —
4. وإلا `FALLBACK_TEMPLATES[eventKey]` المدمج.

**التصيير (render)**: يستبدل `{var}` بقيم من مصفوفة المتغيّرات؛ أي متغيّر غير معروف يُصيَّر كنص فارغ. المتغيّرات المتاحة في `trigger()`: `name`, `orderNo`, `total`, `status`, `org`, `branch` + أي `extraVars`.

### 2.7 المزوّد (WhatsAppProvider / LogProvider / canDeliver)

**عقد `MessagingProvider`**: دالة `send(to, body, channel)` تُرجِع `{status, provider, id?}` ولا ترمي على فشل عادي؛ ودالة `canDeliver()` تُجيب: هل هذا المزوّد يُسلِّم فعلاً لشخص حقيقي؟

- **WhatsAppProvider** (`canDeliver()=true`): يستدعي Cloud API عبر HTTP (مهلة 10ث قراءة + 5ث اتصال حتى لا يُعلَّق مسار الدفع/OTP). عند النجاح `SENT` مع id الرسالة. عند الفشل: يُسجَّل تحذير `messaging.whatsapp.failed` **مع إخفاء الرقم (آخر 4 أرقام) وبلا نص الرسالة إطلاقاً** (منعاً لتراكم PII/OTP في السجلات)، ويُرجَع `FAILED`.
- **LogProvider** (`canDeliver()=false`): stub افتراضي حين لا اعتماد. **لا يسجّل النص أبداً** (لأنه يحمل OTP حيّاً — تسريبه في السجل يحوّل الوصول للسجل إلى استيلاء كامل على الحساب)، يسجّل فقط الرقم مُقنّعاً (`****789`) والقناة وطول النص، ويُرجِع `LOGGED`.

**لماذا `canDeliver()`**: تدفّقات OTP يجب أن تفشل مغلقةً (fail-closed) بدل توليد رمز لن يصله أحد. سابقاً كان القرار بمطابقة اسم الدرايفر نصياً في أربعة مواضع، فكان `MESSAGING_DRIVER=sms` (بلا تنفيذ خلفه) يبدو مزوّداً حقيقياً بينما تربط الحاوية stub بصمت. سؤال المزوّد المربوط مباشرةً يزيل التخمين.

### 2.8 الويبهوك (WaWebhookController)

مسار عام يستدعيه Meta:
- **GET `/wa/webhook`** (throttle 60/دقيقة): تحقّق اشتراك Meta — يردّ صدى `hub_challenge` بشرط تطابق `hub_verify_token` مع `WA_WEBHOOK_VERIFY_TOKEN`؛ وإلا 403.
- **POST `/wa/webhook`** (throttle 120/دقيقة): إيصالات الحالة.

**HMAC fail-closed**: يجب أن يحمل الطلب `X-Hub-Signature-256` = `sha256=` + HMAC-SHA256 للجسم الخام بسرّ تطبيق Meta (`WA_APP_SECRET`). المقارنة بـ `hash_equals`. **إذا لم يُضبَط السرّ → كل طلب وارد يُرفَض (403)** — لأن قبول أجسام غير قابلة للتحقّق يتيح لمهاجم تزوير تحديثات حالة وقلب حالة أي `WaMessage`.

**إيصالات الحالة** (`applyStatus`): يُطابَق الصف عبر `providerMessageId`؛ المعرّفات المجهولة تُتجاهَل (أرقام أخرى قد تشارك نفس الويبهوك). الخريطة:
- `sent` → `SENT` + `sentAt` (فقط إن كانت الحالة `QUEUED`).
- `delivered` → `DELIVERED` + `deliveredAt`.
- `read` → `READ` + `readAt`.
- `failed` → `FAILED` + `error` (من `errors.0.title` أو «فشل التسليم»).
- غير ذلك → يُسجَّل `wa.webhook.unknown_status` بلا تغيير.

### 2.9 SendWaMessage (الطابور)

- `tries` و `backoff` قابلان للضبط من سياسة المنصة (`retryAttempts` 1–10، `retryBackoff` افتراضي `[10,60,300]` ثوانٍ)، محسومان وقت الـ dispatch. `timeout = 45ث` حدّ خارجي حتى لا يحجز عاملٌ مُعلَّق فتحته.
- `handle()`: يجلب الصف؛ إن لم يكن موجوداً أو حالته ليست `QUEUED` يخرج (idempotent). يحلّ المزوّد ويُرسِل. `SENT`/`LOGGED` → يُحدَّث الصف `SENT` مع `senderMode` و `providerMessageId` و `sentAt`. غير ذلك: على الطابور غير المتزامن يُعاد الإطلاق بالـ backoff حتى نفاد المحاولات؛ ثم → `FAILED`. على `sync` (التطوير) يفشل سريعاً حتى لا يُعطَّل الدفع.
- `failed()`: شبكة أمان — أي job قُتِل بالمهلة أو استنفد محاولاته على استثناء لا يصل لسطر التحديث، فيبقى الصف `QUEUED` أبداً؛ هنا يُغلَق إلى `FAILED` ليكون لكل رسالة حالة نهائية يتصرّف عليها المشغّل.

### 2.10 شاشة «رسائل واتساب» للمنشأة (WaController)

كل نقاط هذه الشاشة خلف `requireFeature('messaging')`. الكتابات (القوالب، الاختبار) خلف `requireManager`.

- **GET `/wa/overview`**: الاستهلاك مقابل الحدود + إحصائيات الشهر (حسب الحالة/الفئة/الحدث) + اتجاه ستة أشهر + ملخّص الإعداد بلا أسرار + استهلاك كل فرع.
- **GET `/wa/messages`**: سجل الرسائل org-scoped، فلاتر `status`/`category`/`limit` (حتى 200). **إخفاء OTP**: أي رسالة بفئة AUTHENTICATION أو حدث otp يُستبدَل نصّها بـ «•••• رمز تحقق (مخفي)» في العرض — حتى لا يقرأ موظف رمز عميل ويوافق على شحنة بلا العميل.
- **GET/POST/PUT/DELETE `/wa/templates`** و **`/wa/templates/{id}`**: CRUD قوالب المنشأة (تُرجَع أيضاً القوالب الافتراضية للقراءة فقط + قوائم الأحداث/الفئات).
- **POST `/wa/test`**: إرسال رسالة تجريبية (manager) — تُصيَّر بمتغيّرات وهمية (`code=0000`, `orderNo=TEST-1`…) وتُدفَع عبر `queue()` بحدث `test`.

---

## 3. OTP (النموذج المشترك، الأغراض، الأمان)

### 3.1 النموذج المشترك (OtpCode)

جدول `OtpCode` **مشترك** بين كل تدفّقات الرموز في النظام (بوابة العميل، محفظة نقاط البيع، سائق التوصيل، المسوّق، الدفع). الحقول: `orgId`, `phone`, `codeHash` (bcrypt، مخفي دائماً عن التسلسل)، `expiresAt`, `consumedAt`, `attempts`, `purpose`, `createdAt` (بلا `updatedAt`). العتبات المشتركة: صلاحية 5 دقائق، حدّ محاولات (عادةً 5)، استخدام وحيد ذرّي.

### 3.2 التقسيم بـ `purpose` — ولماذا هو أمني

كل تدفّق يُوسِم رموزه بـ `purpose` مميّز، ويقرأ فقط رموز غرضه. هذا **حاجز أمني**، لا مجرد تنظيم:

> سابقاً كانت رموز موافقة المحفظة في نقاط البيع ورموز دخول البوابة تتشارك نفس فضاء `(orgId, phone)`، فرمزٌ طلب الكاشير من العميل تلاوته كان أيضاً رمز دخول صالحاً للبوابة — أي مقايضة تأكيد شحنة على الصندوق بجلسة 30 يوماً على كامل سجل طلبات ذلك العميل.

بفضل `purpose` (وأحياناً `orgId=null`) تبقى الفضاءات منفصلة تماماً.

### 3.3 كل الأغراض (purposes)

| الغرض | التدفّق | نطاق orgId | ملاحظات |
|------|--------|-----------|--------|
| `PORTAL_LOGIN` | دخول بوابة العميل (`PortalAuthController`) | orgId حقيقي | phone + OTP |
| `POS_WALLET` | موافقة العميل على خصم المحفظة في نقاط البيع (`PosOtpController`) | orgId حقيقي | يُنتِج proof token موقّع `kind:pos-otp` |
| `DRIVER_LOGIN` | دخول سائق التوصيل (`DriverAuthController`) | مفتاح سائق | رسالة مستوى منصة |
| `AFFILIATE_LOGIN` | دخول لوحة المسوّق (`AffiliateAuthController`) | **null** (فضاء منفصل عن عملاء المنشآت) | phone + OTP |
| `ORDER_PAYMENT` | تأكيد دفع الطلب الإلكتروني (`PayController`, `MoyasarWebhookController`) | orgId حقيقي | — |
| `SUBSCRIPTION` | (على `OnlineCharge.purpose`، وليس OtpCode) دفع اشتراك المنشأة | — | تصنيف شحنة، لا رمز |

### 3.4 نمط الأمان الموحّد لتدفّق الدخول (يُطبَّق في المسوّق/السائق/البوابة)

- **رمز ثابت للتجربة فقط محلياً**: `demoCode()` (افتراضي `0000`، أو `DEMO_OTP` بطول 4–8) يُعاد كـ `devCode` **فقط** في `local`/`testing`. رمز ثابت في الإنتاج = باب خلفي استيلاء كامل.
- **fail-closed بلا مزوّد**: يُفحَص `canDeliver()` قبل أي بحث؛ إن لا مزوّد ولا بيئة محلية → «خدمة رمز التحقق غير مُهيأة». (في المسوّق، الفحص قبل البحث لأنه مستقل عن الهاتف فتبقى الإجابتان متطابقتين.)
- **حماية التعداد (enumeration oracle)**: هاتف غير مسجّل يُجيب **بنفس شكل** النجاح تماماً (`{sent:true, delivered:true}`) ولا يُصفّ شيئاً — وإلا صار المسار العام دليل هاتف لعملاء المنشأة، وكل إصابة موجبة تستهلك رسالة واتساب حقيقية من حصة المنشأة. حتى نتيجة التسليم الفعلية لكل هاتف تُخفى بشكل ثابت.
- **الاستخدام الوحيد ذرّي**: عند التحقّق، الاستهلاك عبر `UPDATE … WHERE consumedAt IS NULL` فإن رجع 0 صفّاً → «رمز غير صحيح» (منع سكّ جلستين من رمز واحد بتقديمين متزامنين).

---

## 4. البريد الإلكتروني (EmailService)

بريد المنصة → المنشآت. يحلّ قالب حدثٍ (subject + body بمتغيّرات `{var}`) من إعداد المنصة، يصيّره، يلفّه في قشرة HTML بسيطة بسيطة RTL، ويرسله عبر Laravel Mail (نقل SMTP من `MAIL_*`). best-effort: الفشل يُسجَّل (`email.failed`)، لا يُرمى.

**الأحداث (EVENTS)**: `welcome`, `invoice`, `dunning`, `suspended`, `trialEnding`. لكل حدث قالب افتراضي (subject + body عربي) قابل لإعادة كتابته من الأدمن (`platform.emailTemplates`، تُدمَج التعديلات فوق الافتراضات، تُقبَل فقط مفاتيح `subject`/`body`).

**المتغيّرات الشائعة**: `{name}`, `{org}`, `{amount}`, `{link}`, `{dueDate}`.

**من يستهلكه**:
- `AdminEmailController` (شاشة قوالب البريد بالأدمن + إرسال اختباري).
- `DunningService` (التذكير الآلي): يرسل عبر القنوات المفعّلة (بريد إن `channels.email` و `org.email`؛ واتساب إن `channels.whatsapp` و `org.phone` عبر `WaService::queue` بفئة UTILITY و `orgId=null`)، مرة واحدة لكل مفتاح، ويوسم الإرسال. عند تجاوز `graceDays` (افتراضي 14) يوقف المنشأة (`isSuspended=true`) ويرسل حدث `suspended`.

`render()` هنا يُبقي المتغيّر غير المعروف كما هو (بخلاف `WaService::render` الذي يفرّغه).

---

## 5. الإشعارات والتنبيهات (alerts، notifications)

### 5.1 تنبيهات المنشأة الحيّة — GET `/alerts` (AlertsController)

تنبيهات تشغيلية مشتقّة حيّاً من البيانات الحالية (لا جدول لها)، tenant-scoped، بتوقيت `Asia/Riyadh`. **المجموعات الست** (بالترتيب المعروض):

1. **`portalDelivery`** — «طلبات توصيل جديدة من البوابة»: `DeliveryRequest` بمصدر `PORTAL` وحالة `REQUESTED` (عميل ينتظر؛ توضَع أولاً).
2. **`late`** — «طلبات متأخّرة عن موعدها»: طلبات نشطة في `RECEIVED/PROCESSING/READY` تجاوزت `dueAt`.
3. **`unpaid`** — «سلال غير مدفوعة»: طلبات نشطة غير ملغاة بحالة دفع ضمن `['UNPAID','PARTIAL','DEFERRED']` (تُحمل مبلغاً إجمالياً = مجموع المتبقّي).
4. **`subExpiry`** — «اشتراك المنصّة على وشك الانتهاء»: اشتراك المنشأة إذا تبقّى ≤ 7 أيام (أو منتهٍ).
5. **`lowStock`** — «أصناف مخزون منخفض»: أصناف نشطة `quantity <= reorderLevel`.
6. **`lapsed`** — «عملاء متعثّرون (>45 يوماً)»: عميل سبق أن طلب لكن لا طلب في آخر 45 يوماً (مستثنى عميل walk-in المشترك `0000000000`).

كل مجموعة تحمل: `key`, `title`, `count` (الإجمالي الكامل دائماً)، `tone`, `icon`, `amount?`, و `items` (حتى 20 صفاً). `total` في الرأس = مجموع كل `count`.

### 5.2 سجل الإشعارات الصادرة — GET `/notifications`

سجل الرسائل الصادرة (أو المحاولة) لعملاء المنشأة، من جدول `Notification` (تكتبه تدفّقات Next.js؛ للقراءة فقط هنا — بلا `$fillable`). النطاق عبر `customer.orgId` (لا عمود org له). الحقول المعروضة: `customerId/Name/Phone`, `channel`, `template`, `body`, `status`, `refId`, `sentAt`, `createdAt`. حتى 100 صف، الأحدث أولاً.

### 5.3 تنبيهات المنصة (الأدمن) — GET `/admin/notifications` (AdminNotificationController)

للقراءة فقط، تحت حارس System-Admin. لا جدول — مشتقّة حيّاً من إشارات قائمة، وكل نوع مبوّب خلف صلاحية منصة **وتبديل** قابل للضبط (`مركز الإعدادات → التنبيهات`، البوابة عند القراءة لا عند الإدراج). إن كان `notifications.enabled=false` تُرجَع أصفار:

- **(a) leads**: عملاء محتملون جدد باردون في المسار (`NEW/CONTACTED`) آخر 14 يوماً — بشرط `manage_leads` وتبديل `lead`.
- **(b) support**: تذاكر تحتاج انتباهنا: `OPEN`، أو `PENDING` حيث آخر متحدّث هو `TENANT` (الكرة في ملعبنا) — بشرط `manage_support` وتبديل `support`.
- **(c) trialEnding**: فترات تجربة تنتهي خلال 3 أيام — بشرط `manage_subscriptions` وتبديل `trialEnding`.
- **(d+e) lapsed + past_due**: اشتراكات ACTIVE/TRIAL منتهية + اشتراكات PAST_DUE (تبديل واحد `subscriptionLapse`، عدّاد واحد `lapsed`).
- **(f) churn**: أحداث تسرّب آخر 14 يوماً (`CANCEL_SCHEDULED`, `SUSPEND`, `EXPIRE`) — بشرط تبديل `churn`.

الناتج: تغذية مدمَجة (الأحدث أولاً، حتى 60) + عدّادات لكل فئة + `total`. الخطورة (`severity`): info/warning/critical حسب النوع/الأولوية.

---

## 6. الأتمتة الخلفية (AutomationSweeper)

### 6.1 مشغّل ترقية الطلبات

`AutomationSweeper::sweep()` — تمريرة واحدة على كل منشأة فعّلت الأتمتة (`automation.config:{orgId}` بمفتاح `enabled`)، تُقدِّم الطلبات التي تجاوزت عتبة عمرها المضبوطة نحو READY وتكتب صف `OrderStatusHistory` لكل قفزة.

- **الانتقالات المسموحة (ORDER_FLOW)**: `RECEIVED→PROCESSING→READY`؛ الترقية التلقائية تقفز إلى READY فقط (لا DELIVERED).
- **حساب التأخير (resolveDelayMinutes)**: حسب الأولوية (EXPRESS→express وإلا normal) ونوع الخدمة؛ قاعدة نوع الخدمة تتقدّم على الافتراضي؛ يُؤخَذ **أقصى** تأخير بين أنواع خدمات الطلب. `default` الافتراضي: `normal=180`, `express=30` دقيقة. أنواع الخدمة: WASH/IRON/WASH_IRON.
- الطلبات المرشّحة: `RECEIVED/PROCESSING` فقط، أقدم أولاً، حتى 500 لكل منشأة. من تجاوز `createdAt + delay` يُرقّى.
- **عند بلوغ READY**: يُطلَق حدث واتساب `orderReady` (best-effort، مبوّب/محجوز، لا يكسر التمريرة).
- الناتج: `{orgs, scanned, advanced}`.

### 6.2 الجدولة

- أمر `automation:sweep` (AutomationSweepCommand)، مجدول كل 5 دقائق في `bootstrap/app.php` مع `withoutOverlapping()`. يشاركه أيضاً مسار HTTP (CronController) بنفس التنفيذ.
- أتمتة أخرى ذات صلة: `SweepSubscriptions` (ليلي، لإسقاط الاشتراكات/التجارب المنتهية) و `DunningService` (تذكير/إيقاف الدفع، §4).

---

## 7. الدعم و CRM (تذاكر المنشأة، مراسلة المشرفين، CrmNote، Lead)

### 7.1 الدعم — جانب المنشأة (OrgSupportController)

مسارات `/org/support*` تحت `auth.api` (staff-scoped، مقيّدة لـ org المتصل):
- **GET `/org/support`**: تذاكر المنشأة (الأحدث نشاطاً أولاً، حتى 100) + فئات التذاكر المُهيّأة من المنصة (`support.categories`).
- **GET `/org/support/{id}`**: تذكرة + رسائلها (الأقدم أولاً).
- **POST `/org/support`**: فتح تذكرة برسالة أولى. الحقول: `subject`, `body`, `priority?` (LOW/NORMAL/HIGH/URGENT)، `category?` (من فئات المنصة). **لا عمود category** في `SupportTicket` (Prisma-owned) → تُبأدَأ الفئة في `subject` بين قوسين. تُنشأ الحالة `OPEN`. **رد آلي اختياري**: إن كان `support.autoReplyEnabled` ونص `autoReplyText` غير فارغ، تُضاف رسالة `ADMIN` تلقائية.
- **POST `/org/support/{id}/reply`**: رد المنشأة — **يعيد فتح** التذكرة إن كانت `RESOLVED/CLOSED` (تعود `OPEN`) ويحدّث `lastReplyAt`.

### 7.2 الدعم — جانب الأدمن (AdminSupportController)

القراءات لأي system admin؛ الكتابات خلف صلاحية `manage_support`. كل الكتابات مُدقَّقة (`auditAdmin`).
- **GET `/admin/support`**: صندوق التذاكر (فلتر `status` اختياري) + عدّادات لكل حالة. يحسب `lastAuthorType` (هل المنشأة تنتظرنا؟) و `slaBreached` (إن تجاوز الانتظار `support.slaResponseMinutes`).
- **GET `/admin/support/{id}`**: تذكرة + اسم المنشأة + كامل الخيط.
- **POST `/admin/support/{id}/reply`**: رد الأدمن — يرفع `lastReplyAt` وينقل الحالة إلى `PENDING` (بانتظار المنشأة).
- **PUT `/admin/support/{id}`**: تغيير `status`/`priority`/`assignedToId` (null صريح = إلغاء الإسناد).

**حالات التذكرة (STATUSES)**: `OPEN`, `PENDING`, `RESOLVED`, `CLOSED`. **الأولويات**: `LOW`, `NORMAL`, `HIGH`, `URGENT`. **نوع المؤلّف (authorType)**: `TENANT`, `ADMIN`.

### 7.3 مراسلة المشرفين الداخلية (AdminMessageController)

مراسلة أدمن↔أدمن: 1:1 (DM) وقنوات فرق (CHANNEL)، مع @mentions، إيصالات قراءة، عدّادات غير مقروء، سير عمل متابعة/محلول، وربط اختياري بعنصر عمل. الوصول **participant-scoped** ويُفرَض في كل إجراء (لا يثق بالعميل أبداً؛ الهوية تُحلّ حيّاً من التوكن). قرب-اللحظي عبر polling.

- **GET `/admin/messages/recipients`**: الأدمن الذين يمكن مراسلتهم (`isPlatformOwner` نشطون، عدا النفس).
- **GET `/admin/messages/conversations`**: صندوق المتصل (الأحدث نشاطاً)، مع غير-المقروء وآخر رسالة لكل محادثة (استعلامان مجمّعان).
- **POST `/admin/messages/conversations`**: بدء DM أو إنشاء CHANNEL. DM يُعاد استخدام محادثة 1:1 قائمة بدل التكرار؛ لا يمكن مراسلة النفس. ربط اختياري `linkedType` ∈ `Organization/Lead/SupportTicket` (يُحلّ الاسم خادمياً).
- **GET `/admin/messages/conversations/{id}`**: الخيط + الرسائل؛ يوسمها مقروءة.
- **PATCH `/admin/messages/conversations/{id}`**: أعلام `followUp`/`resolved`.
- **POST `.../messages`**: إرسال رسالة (mentions تُصفّى للأعضاء الفعليين فقط).
- **POST `.../read`**: وسم مقروء.
- **POST `.../participants`**: إضافة أعضاء (قنوات فقط).
- **PATCH `/admin/messages/messages/{id}`**: تعديل رسالة المرء خلال نافذة 15 دقيقة.
- **DELETE `/admin/messages/messages/{id}`**: حذف ناعم لرسالة المرء (`deletedAt`؛ يبقى الخيط بشكله «رسالة محذوفة»).
- **GET `/admin/messages/unread`**: إجماليات للبادج (total + mentions).

**نوع المحادثة (kind)**: `DM`, `CHANNEL`. **العناصر المرتبطة**: `Organization`, `Lead`, `SupportTicket`.

### 7.4 CRM — قائمة الانتباه والملاحظات (AdminCrmController)

نظام CRM مبسّط لمتابعة المنشآت واشتراكاتها، خلف صلاحية `manage_crm`، مدعوم بجدول `CrmNote`:
- **GET `/admin/crm`**: **قائمة الانتباه** (اشتراكات تحتاج متابعة: `past_due`, `trial_ending`, `expired`, `canceling` + المنشآت الموقوفة `suspended`) + آخر 100 ملاحظة متابعة.
- **POST `/admin/crm/notes`**: إضافة ملاحظة/مهمة ضد منشأة أو عميل محتمل (يلزم أحدهما).
- **POST `/admin/crm/notes/{id}/done`**: وسم مهمة منجزة (`doneAt`).

**أنواع CrmNote (kind)**: `NOTE`, `CALL`, `EMAIL`, `MEETING`, `TASK`. الحقول: `leadId`, `orgId`, `kind`, `body`, `dueAt`, `doneAt`, `authorId`, `createdAt` (بلا timestamps تلقائية).

### 7.5 العملاء المحتملون / خط المبيعات (AdminLeadController)

مسار مبيعات المنشآت المرتقبة، كل كتابة خلف `manage_leads`:
- **GET `/admin/leads`**: اللوحة كاملة (leads + اسم المالك + ملاحظات + عدّ) + المراحل + المالكون + KPIs (total/open/wonThisMonth/pipelineValue) + الخطط.
- **POST `/admin/leads`**: إنشاء (يبدأ `NEW`؛ `source` الافتراضي من `marketing.defaultLeadSource`).
- **PUT `/admin/leads/{id}`**: تعديل حقول، نقل المرحلة، (إعادة) إسناد المالك، `lostReason` (مطلوب إن `stage=LOST`). أول دخول WON يختم `wonAt`.
- **POST `/admin/leads/{id}/notes`**: إضافة ملاحظة CRM للخط الزمني.
- **POST `/admin/leads/{id}/convert`**: تحويل العميل المحتمل إلى منشأة حقيقية (عبر `TenantProvisioner`) ثم وسمه `WON` (يمنع التحويل المكرّر عبر `convertedOrgId`).

**مراحل Lead (STAGES)**: `NEW`, `CONTACTED`, `QUALIFIED`, `WON`, `LOST`. الحقول: `businessName`, `contactName`, `phone`, `email`, `city`, `source`, `stage`, `expectedMrr`, `ownerId`, `convertedOrgId`, `lostReason`, `wonAt`.

---

## 8. المجتمع (المنتدى)

المنتدى **عالمي (platform-wide)**، ليس tenant-scoped؛ `ForumThread.orgId` للإسناد فقط. القراءات تتطلّب مستخدم staff مُصادَقاً.

### 8.1 المنتدى (ForumController)

- **GET `/forum/categories`**: التصنيفات النشطة (لشريط الفلترة).
- **GET `/forum/threads`**: المواضيع المعتمَدة (`APPROVED`)، المثبّت أولاً ثم الأحدث نشاطاً، فلتر `?category=<slug>` اختياري، حتى 100.
- **GET `/forum/threads/{id}`**: موضوع معتمَد + ردوده المعتمَدة (يرفع `viewCount`).
- **POST `/forum/threads`**: موضوع جديد → يبدأ **`PENDING`** (غير مرئي علناً حتى يوافق مشرف). **حدّ**: 3 مواضيع كحد أقصى في طابور المراجعة للمؤلّف الواحد (وإلا 429). التصنيف يجب أن يكون موجوداً ونشطاً.
- **POST `/forum/threads/{id}/posts`**: ردّ على موضوع معتمَد **غير مغلق** — الردود **post-moderated** (تلقائياً `APPROVED`)، ترفع `replyCount` و `lastActivityAt`.

**حالات الموضوع/الرد (status)**: `PENDING`, `APPROVED` (وضمناً مرفوض عبر `rejectionReason`). أعلام الموضوع: `isPinned`, `isClosed`. **نوع المؤلّف**: `USER`. أسماء المؤلّفين تُحَل من `User` (id+name فقط، لا email/role — منعاً لتسريب بيانات الطاقم). الـ slug يحفظ الحروف العربية.

### 8.2 موجز المجتمع للمنشأة (CommunityController::feed)

- **GET `/community`**: عرض منسّق فوق المنتدى العالمي — مواضيع المتصل نفسه (أي حالة، الأحدث، حتى 50) + التصنيفات النشطة لمُنتقي «موضوع جديد». إنشاء الموضوع يعيد استخدام `POST /forum/threads` بنفس تدفّق PENDING.

---

## 9. المحتوى (المدوّنة، السوشيال، إعلانات المنشأة)

### 9.1 المدوّنة العالمية (BlogController)

المدوّنة **عالمية**، غير tenant-scoped. القراءات علنية للمنشورات `PUBLISHED` ذات `publishedAt` في الماضي؛ **الكتابة لمالك المنصة فقط** (`isPlatformOwner`).
- **GET `/blog/categories`**, **GET `/blog/posts`** (فلتر `?category`), **GET `/blog/posts/{slug}`** (يرفع `viewCount`).
- **POST `/blog/posts`** (مالك المنصة): يبدأ `DRAFT` ما لم يُرسَل `status=PUBLISHED` (يختم `publishedAt`).
- **PUT `/blog/posts/{id}`** (مالك المنصة): أول نشر يختم `publishedAt` ويُحفَظ بعدها.

**حالات المنشور (status)**: `DRAFT`, `PUBLISHED`, `ARCHIVED`. حقول ثنائية اللغة: `title/titleEn`, `excerpt/excerptEn`, `content/contentEn`. `tags` عمود Postgres `text[]` يُعالَج يدوياً (parse/toPgArray). slug فريد (يلحق -2, -3…) يحفظ العربية.

### 9.2 منشورات السوشال للمنصة (AdminSocialPostController)

تقويم منشورات السوشال الخاصة **بالمنصة نفسها** (تسويق غسلة، ليس محتوى مستأجرين). تنظيم/جدولة داخلية؛ النشر الفعلي **يدوي** على المنصات. كل الكتابات خلف `manage_marketing` ومُدقَّقة.
- **GET `/admin/marketing/social-posts`**: كل المنشورات (الأقرب جدولةً أولاً) + عدّادات (draft/scheduled/published).
- **POST** و **PUT `/{id}`**: إنشاء/تعديل (الصور base64، يُستبدَل ما لا يُبقى ويُحذف من القرص). أول نشر يختم `publishedAt`.
- **POST `/{id}/publish`**: وسم «تم النشر» يدوياً.
- **DELETE `/{id}`**: حذف الصور من القرص ثم الصف.
- **GET `/admin/social-images/{name}`**: عرض صورة عبر **رابط موقّع مؤقت** (12 ساعة) — التوقيع الزمني هو التفويض (بلا ترويسة Bearer، ليُفتَح في تبويب جديد).

**المنصات (PLATFORMS)**: `TWITTER`, `INSTAGRAM`, `WHATSAPP`, `TIKTOK`, `SNAPCHAT`, `LINKEDIN` (المتاح منها قابل للضبط عبر `content.enabledPlatforms`). **الأنواع (KINDS)**: `IMAGE`, `CAROUSEL`, `STORY`, `REEL`, `TEXT`. **الحالات**: `DRAFT`, `SCHEDULED`, `PUBLISHED`. سقف الصور لكل منشور قابل للضبط (`content.maxImagesPerPost`، افتراضي 6). الصور: JPEG/PNG فقط (تحقّق بايتات سحرية)، ≤ 10 ميجابايت، تُخزَّن تحت `social-posts/`. `imageUrls` عمود `text[]`.

### 9.3 إعلانات المنشأة (OrgAnnouncement / CommunityController)

إعلانات المنشأة الخاصة المعروضة **لعملائها** في كاروسيل بوابة العميل (مختلفة عن `PlatformAnnouncement` من المنصة → المنشأة).
- **GET `/announcements`** (staff): إعلانات المنشأة (حتى 100، الأحدث).
- **POST/PUT/DELETE `/announcements`**: CRUD — **خلف `requireManager`** (سطح علامة/تصيّد يُعرَض لعملاء نهائيين، فيُقيَّد للمدراء لا أي staff).
- **GET `/portal/announcements`** (kind=customer، عبر `ResolvesCustomer`): الإعلانات النشطة لكاروسيل البوابة (يرفض توكن staff).

الحقول: `orgId`, `title`, `body`, `imageUrl` (URL نصّي، regex يقبل `http(s)://` أو `/`)، `isActive` (افتراضي true)، `createdAt` (بلا updatedAt).

---

## 10. التسويق بالعمولة (Affiliate)

سطح **مستقل** للمسوّقين/الشركاء بتوكنه الخاص (`kind === 'affiliate'`)، منفصل عن staff/customer/supplier. جدول `Affiliate` المشترك بلا عمود كلمة مرور → الدخول **هاتف + OTP** (يعيد استخدام `OtpCode` بـ `orgId=null` وغرض `AFFILIATE_LOGIN`).

### 10.1 المصادقة (AffiliateAuthController) — عامة

- **POST `/affiliate/auth/register`** (throttle otp-request): تسجيل ذاتي `{name, email, phone}`. `email` فريد، `phone` مطلوب وفريد (هو معرّف الدخول). يُولَّد **كود إحالة** فريد (`generateUniqueCode`: من الاسم + لاحقة عشوائية، uppercase URL-safe، يُعاد حتى التفرّد) ويُصدَر توكن فوراً.
- **POST `/affiliate/auth/request-otp`** (throttle otp-request): يطبّق نمط الأمان الموحّد (§3.4): fail-closed بلا مزوّد، demoCode+devCode محلياً فقط، **حماية التعداد** (هاتف غير معروف يُجيب كنجاح ولا يصفّ شيئاً)، رسالة مستوى منصة بلا حصة org.
- **POST `/affiliate/auth/verify-otp`** (throttle otp-verify): تحقّق (صلاحية 5د، حد `MAX_ATTEMPTS=5`، استهلاك ذرّي) ويُصدِر توكن affiliate (صلاحية 30 يوماً).
- **GET `/r/{code}`** (عام): محلّل صفحة هبوط الإحالة → `{affiliateName, code, signupUrl}`؛ يُلحِق وسوم UTM اختيارياً من `marketing` (إن `appendUtmToReferral`).

### 10.2 لوحة المسوّق (AffiliateController) — بتوكن affiliate عبر ResolvesAffiliate

- **GET `/affiliate/me`**: الملف + إحصائيات العمولة. **تعريف «محوّل» (converted)**: الإحالة محوّلة حين يكون للمنشأة المُحالة اشتراك منصة **`ACTIVE`**. التبويب: `CANCELLED` تُستثنى؛ `PAID` عمولة مدفوعة؛ `PENDING + APPROVED` عمولة معلّقة. الإحصائيات: `referrals`, `converted`, `pending`, `paid`, `total`.
- **GET `/affiliate/referrals`**: سجلات إحالات المسوّق (اسم المنشأة، الخطة، مبلغ الاشتراك، العمولة، الحالة، تواريخ).

### 10.3 العمولة

- **نوع العمولة (commissionType)** ومعدّلها (`commissionRate`) على نموذج `Affiliate` (float).
- **حالات الإحالة (AffiliateReferral.status)**: `PENDING`, `APPROVED`, `PAID`, `CANCELLED`. الحقول: `affiliateId`, `orgId`, `planName`, `subAmount`, `commission`, `status`, `paidAt`, `createdAt`.

---

## 11. الكيانات (كل حقول كل كيان — مجمّعة)

> كل الكيانات تمتد `PrismaModel` (جدول PascalCase، مفتاح cuid نصّي، `$incrementing=false`) ما لم يُذكر خلاف ذلك.

**WaMessage** (سجل محاولة إرسال، مصدر الحصص): `orgId`, `branchId`, `customerId`, `orderId`, `toPhone`, `channel`, `category`, `eventKey`, `templateId`, `body`, `senderMode`, `status`, `providerMessageId`, `error`, `sentAt`, `deliveredAt`, `readAt`, `createdAt`. الحالات: `QUEUED→SENT→DELIVERED→READ` أو `FAILED`/`BLOCKED`. `COUNTED_STATUSES = [QUEUED,SENT,DELIVERED,READ]`.

**WaTemplate**: `orgId` (null = افتراضي منصة), `name`, `category`, `eventKey` (null = قالب إرسال يدوي), `body` (بمتغيّرات {var}), `isActive`, `createdById`.

**OtpCode** (مشترك، `timestamps=false`، `codeHash` مخفي): `orgId`, `phone`, `codeHash` (bcrypt), `expiresAt`, `consumedAt`, `attempts`, `purpose`, `createdAt`.

**Notification** (للقراءة فقط، `UPDATED_AT=null`، بلا fillable): `customerId`, `channel`, `template`, `body`, `status`, `refId`, `sentAt`, `createdAt` + علاقة `customer`.

**Conversation**: `kind` (DM/CHANNEL), `title`, `createdById`, `linkedType`, `linkedId`, `linkedLabel`, `followUp`, `resolvedAt`, `lastMessageAt`, `createdAt`, `updatedAt`.

**ConversationParticipant** (`UPDATED_AT=null`): `conversationId`, `userId`, `lastReadAt`, `createdAt` (مصدر وحيد للغير-مقروء وإيصالات القراءة).

**Message** (`UPDATED_AT=null`): `conversationId`, `authorId`, `body`, `mentions` (مصفوفة JSON بمعرّفات مذكورين), `editedAt`, `deletedAt` (حذف ناعم), `createdAt`.

**SupportTicket**: `orgId`, `subject`, `status` (OPEN/PENDING/RESOLVED/CLOSED), `priority` (LOW/NORMAL/HIGH/URGENT), `createdById`, `assignedToId`, `lastReplyAt`, `createdAt`, `updatedAt` + علاقات `messages`, `org`. (بلا عمود category — يُبأدأ في subject.)

**SupportMessage** (`UPDATED_AT=null`): `ticketId`, `authorType` (TENANT/ADMIN), `authorId`, `body`, `createdAt`.

**CrmNote** (`timestamps=false`): `leadId`, `orgId`, `kind` (NOTE/CALL/EMAIL/MEETING/TASK), `body`, `dueAt`, `doneAt`, `authorId`, `createdAt` + علاقة `lead`.

**Lead**: `businessName`, `contactName`, `phone`, `email`, `city`, `source`, `stage` (NEW/CONTACTED/QUALIFIED/WON/LOST), `expectedMrr`, `ownerId`, `convertedOrgId`, `lostReason`, `wonAt`, `createdAt`, `updatedAt` + علاقة `notes`.

**BlogPost**: `categoryId`, `title`, `titleEn`, `slug`, `excerpt`, `excerptEn`, `content`, `contentEn`, `coverImageUrl`, `tags` (Postgres text[]), `status` (DRAFT/PUBLISHED/ARCHIVED), `publishedAt`, `createdById`, `viewCount` + علاقة `category`.

**BlogCategory**: `name`, `nameEn`, `slug`, `sortOrder`, `isActive` + علاقة `posts`.

**ForumCategory**: `name`, `nameEn`, `slug`, `description`, `sortOrder`, `isActive` + علاقة `threads`.

**ForumThread**: `orgId` (إسناد فقط), `categoryId`, `title`, `slug`, `body`, `authorType` (USER), `authorId`, `status` (PENDING/APPROVED), `rejectionReason`, `isPinned`, `isClosed`, `replyCount`, `viewCount`, `lastActivityAt` + علاقات `category`, `posts`.

**ForumPost**: `threadId`, `authorType` (USER), `authorId`, `body`, `status` (APPROVED افتراضياً) + علاقة `thread`.

**SocialPost**: `title`, `platform` (TWITTER/INSTAGRAM/WHATSAPP/TIKTOK/SNAPCHAT/LINKEDIN), `kind` (IMAGE/CAROUSEL/STORY/REEL/TEXT), `caption`, `imageUrls` (Postgres text[]), `scheduledAt`, `publishedAt`, `status` (DRAFT/SCHEDULED/PUBLISHED), `notes`, `createdById`.

**OrgAnnouncement** (`UPDATED_AT=null`): `orgId`, `title`, `body`, `imageUrl`, `isActive`, `createdAt` + علاقة `organization`.

**Affiliate**: `name`, `email`, `phone`, `code` (كود إحالة فريد), `commissionType`, `commissionRate` (float), `isActive`, `notes` + علاقة `referrals`.

**AffiliateReferral** (`timestamps=false`): `affiliateId`, `orgId`, `planName`, `subAmount`, `commission`, `status` (PENDING/APPROVED/PAID/CANCELLED), `paidAt`, `createdAt` + علاقتا `affiliate`, `organization`.

---

## 12. قواعد البيزنس (مرقّمة)

1. **بوابة واحدة**: كل رسالة واتساب/SMS تمر حصراً عبر `WaService::queue()`؛ لا مسار إرسال بديل.
2. **best-effort لا يُفشِل الأصل**: `queue()` لا يرمي على حجب تجاري (يعيد `BLOCKED`)؛ المزوّد لا يرمي على فشل عادي؛ `trigger()` من مسارات الطلبات ملفوف بـ try/catch.
3. **رسائل المنصة (orgId=null) تتجاوز حصص org**: بلا قفل ولا فحص حصة org — لكن الحارس ما زال يمنع حجب فئة AUTHENTICATION على مستوى المنصة.
4. **AUTHENTICATION لا تُحجب أبداً** بمفتاح إيقاف المنصة العام (طبقة 1) — رموز الدخول تصل دائماً.
5. **عدّ الحصة يشمل QUEUED**: صف مصفوف يُحتسب فوراً، لذا فحص-الحصة + الإدراج ذرّيان تحت `pg_advisory_xact_lock` لكل org.
6. **الفرع يرث حصة المنصة الافتراضية** إن لم يكن له override صريح في `branchLimits`.
7. **ساعات الهدوء تُسقِط الرسالة** (لا تؤجّلها) وتخص الفئات المضبوطة فقط (افتراضياً MARKETING)؛ OTP/UTILITY لا تتأهّل.
8. **وضع CUSTOM يتطلّب token+phoneId** كليهما غير فارغين، وإلا يسقط تلقائياً لـ PLATFORM.
9. **أولوية القالب**: قالب org نشط ← قالب منصة افتراضي ← نص legacy ← احتياطي مدمج.
10. **الويبهوك fail-closed**: بلا `WA_APP_SECRET` تُرفَض كل الإيصالات؛ ومع السرّ يجب تطابق HMAC؛ المعرّفات المجهولة تُتجاهَل.
11. **إخفاء OTP في السجلات والعرض**: لا نص OTP في سجل المزوّد ولا في `/wa/messages` (يُستبدَل بـ «رمز تحقق مخفي»)؛ الأرقام تُقنَّع.
12. **تقسيم OTP بـ purpose حاجز أمني**: كل تدفّق يقرأ فقط رموز غرضه؛ المسوّق يستخدم فضاء `orgId=null` منفصلاً عن عملاء المنشآت.
13. **رمز ثابت للتجربة محلياً فقط**: `0000`/`DEMO_OTP` و `devCode` في local/testing حصراً؛ الإنتاج رمز عشوائي عبر المزوّد أو fail-closed.
14. **حماية تعداد الهواتف**: مسارات طلب-OTP العامة تُجيب بشكل ثابت للهاتف المجهول ولا تستهلك رسالة.
15. **استهلاك OTP ذرّي**: `UPDATE … WHERE consumedAt IS NULL`؛ رجوع 0 = رفض (منع سكّ جلستين).
16. **موضوع منتدى جديد = PENDING** حتى موافقة مشرف؛ الردود post-moderated (auto-APPROVED).
17. **حدّ طابور المراجعة**: 3 مواضيع PENDING كحد أقصى للمؤلّف (وإلا 429).
18. **الردود تتطلّب موضوعاً APPROVED غير مغلق**.
19. **المنتدى والمدوّنة عالميان**: غير tenant-scoped؛ `orgId` على الموضوع للإسناد فقط.
20. **كتابة المدوّنة لمالك المنصة فقط** (`isPlatformOwner`)؛ المنشور يبدأ DRAFT وأول نشر يختم `publishedAt` ويُحفَظ.
21. **إعلانات المنشأة خلف requireManager** (سطح موجَّه للعملاء)؛ قراءة البوابة تتطلّب توكن customer وترفض staff.
22. **رد المنشأة يعيد فتح التذكرة** من RESOLVED/CLOSED؛ رد الأدمن ينقلها إلى PENDING.
23. **تذكرة بلا عمود category**: الفئة تُبأدَأ في subject بين قوسين.
24. **مراسلة المشرفين participant-scoped مفروضة في كل إجراء**؛ DM يُعاد استخدامه؛ mentions للأعضاء فقط؛ تعديل خلال 15 دقيقة؛ حذف ناعم.
25. **CrmNote يلزمه org أو lead** (أحدهما على الأقل).
26. **تحويل Lead لا يتكرّر** (يُمنَع عبر `convertedOrgId`) ويوسمه WON ويختم `wonAt`.
27. **«محوّل» = اشتراك منصة ACTIVE** للمنشأة المُحالة؛ `CANCELLED` تُستثنى من كل الحسابات.
28. **الأتمتة تُرقّي إلى READY فقط** (لا DELIVERED)، تأخذ أقصى تأخير بين أنواع الخدمات، وتُطلق `orderReady` عند البلوغ.
29. **تنبيهات المنشأة والمنصة مشتقّة حيّاً** (لا جداول)؛ تبويب تنبيهات المنصة خلف صلاحية + تبديل عند القراءة لا الإدراج.
30. **بريد/واتساب المنصة عبر قنوات مفعّلة**: Dunning يرسل مرة لكل مفتاح ويوسمه؛ تجاوز graceDays يوقف المنشأة.

---

## 13. الأدوار والصلاحيات + قائمة العمليات الكاملة

### 13.1 بوابات الصلاحية حسب السطح

- **staff (ResolvesTenant)**: `assertStaff` (يرفض توكن customer/supplier)، `requireManager`, `requireSuperAdmin`، و `requireFeature('messaging')` لشاشة واتساب.
- **customer (ResolvesCustomer)**: `customerOrgId` يؤكّد `kind=customer`.
- **affiliate (ResolvesAffiliate)**: `affiliateId` يؤكّد `kind=affiliate` (401 لغيره).
- **platform owner / system admin (RequiresPlatformOwner)**: `assertSystemAdmin`, `assertPlatformPermission(perm)`, `hasPlatformPermission(perm)`, `platformUserId`؛ صلاحيات: `manage_support`, `manage_crm`, `manage_leads`, `manage_marketing`, `manage_subscriptions`. الكتابات مُدقَّقة عبر `auditAdmin`.
- **عام (بلا توكن)**: ويبهوك واتساب (محمي بـ verify token / HMAC)، تسجيل/دخول المسوّق (OTP + throttle)، هبوط الإحالة `/r/{code}`.

### 13.2 قائمة العمليات الكاملة

| العملية | المسار | الصلاحية |
|--------|-------|---------|
| تحقّق ويبهوك واتساب | GET /wa/webhook | عام (verify token) |
| استقبال إيصالات واتساب | POST /wa/webhook | عام (HMAC fail-closed) |
| نظرة عامة واتساب | GET /wa/overview | staff + feature messaging |
| سجل رسائل واتساب | GET /wa/messages | staff + feature (OTP مخفي) |
| قوالب واتساب (عرض) | GET /wa/templates | staff + feature |
| قوالب واتساب (إنشاء/تعديل/حذف) | POST/PUT/DELETE /wa/templates[/{id}] | requireManager + feature |
| رسالة تجريبية | POST /wa/test | requireManager + feature |
| موجز المجتمع | GET /community | staff |
| إعلانات المنشأة (عرض) | GET /announcements | staff |
| إعلانات المنشأة (إنشاء/تعديل/حذف) | POST/PUT/DELETE /announcements[/{id}] | requireManager |
| إعلانات البوابة | GET /portal/announcements | customer |
| تصنيفات/مواضيع المنتدى (عرض) | GET /forum/categories, /forum/threads[/{id}] | staff |
| موضوع منتدى جديد | POST /forum/threads | staff (يبدأ PENDING) |
| ردّ منتدى | POST /forum/threads/{id}/posts | staff |
| مدوّنة (عرض) | GET /blog/categories, /blog/posts[/{slug}] | staff |
| مدوّنة (إنشاء/تعديل) | POST/PUT /blog/posts[/{id}] | platform owner |
| تنبيهات المنشأة | GET /alerts | staff |
| إشعارات المنشأة الصادرة | GET /notifications | staff |
| دعم المنشأة (عرض/فتح/رد) | GET/POST /org/support[/{id}][/reply] | staff (org-scoped) |
| دعم الأدمن (صندوق/عرض/رد/تحديث) | GET/POST/PUT /admin/support[/{id}][/reply] | manage_support |
| CRM (قائمة/ملاحظة/إنجاز) | GET/POST /admin/crm[/notes][/{id}/done] | manage_crm |
| Leads (لوحة/إنشاء/تعديل/ملاحظة/تحويل) | /admin/leads* | manage_leads |
| مراسلة المشرفين (كل العمليات) | /admin/messages/* | system admin + participant-scoped |
| منشورات السوشال (كل العمليات) | /admin/marketing/social-posts* | manage_marketing |
| عرض صورة سوشال | GET /admin/social-images/{name} | رابط موقّع مؤقت |
| تنبيهات المنصة | GET /admin/notifications | system admin + صلاحيات/تبديلات لكل نوع |
| تسجيل مسوّق | POST /affiliate/auth/register | عام + throttle |
| طلب/تحقّق OTP مسوّق | POST /affiliate/auth/request-otp, verify-otp | عام + throttle |
| هبوط الإحالة | GET /r/{code} | عام |
| ملف/إحالات المسوّق | GET /affiliate/me, /affiliate/referrals | affiliate |
| بريد المنصة (قوالب/اختبار) | /admin/email* | system admin (AdminEmailController) |

---

## 14. حالات خاصة وفجوات

1. **حجب vs فشل**: `BLOCKED` (حارس تجاري: حصة/تبديل/ساعات هدوء) مختلف عن `FAILED` (خطأ مزوّد بعد المحاولات) — كلاهما حالة نهائية، لكن BLOCKED يُحتسب في الحصة (لأنه ضمن... لا: BLOCKED **ليس** ضمن COUNTED_STATUSES، فلا يستهلك حصة؛ بينما QUEUED يستهلك).
2. **QUEUED عالق**: `SendWaMessage::failed()` شبكة أمان تُغلق أي صف بقي QUEUED بعد قتل بالمهلة/استثناء إلى FAILED — وإلا بقي «فشلاً خفيّاً» أبداً.
3. **LOGGED يُعامَل كـ SENT**: في التطوير يُحدَّث الصف SENT رغم عدم التسليم الفعلي (`canDeliver()=false`)؛ لذا الإحصائيات تعدّه ناجحاً بيئياً.
4. **legacy templates**: خريطة القوالب القديمة محدودة بـ `orderReady/otp/invoice(→paymentLink)` فقط؛ أحداث أخرى تسقط مباشرة للاحتياطي المدمج.
5. **تعارض التوقيت (timezone)**: التخزين UTC-naive؛ `scheduledAt` للسوشال يُحوَّل `->utc()` صراحةً وإلا يُقرأ خطأً؛ نوافذ التنبيهات/التقارير تُحوَّل من `Asia/Riyadh` إلى UTC قبل الاستعلام.
6. **تنبيه فرع فارغ**: `/alerts` بلا فروع يُرجِع هيكل المجموعات الست بأصفار (يحفظ شكل SPA).
7. **إخفاء نتيجة التسليم للمسوّق**: `request-otp` يُعيد شكلاً ثابتاً (`sent:true, delivered:true`) دائماً — الـ SPA لا تقرأ العلم؛ المشغّل يرى الحالة الحقيقية في سجل الرسائل فقط.
8. **`OrgAnnouncement.imageUrl` رابط نصّي فقط** (لا خط رفع على هذا السطح) بخلاف الأصل الذي كان يرفع بانر.
9. **`SupportTicket` بلا category/updatedAt بالمعنى الكامل**: الفئة تُبأدأ في subject؛ SLA و«آخر مؤلّف» يُحسبان حيّاً من الرسائل.
10. **مراسلة المشرفين حصراً بين `isPlatformOwner`**: المستلمون والأعضاء كلهم مالكو منصة نشطون؛ لا يشمل staff المنشآت.
11. **`hasPlatformPermission` يبوّب مصادر تنبيهات المنصة**: أدمن بلا `manage_leads` لا يرى leads في `/admin/notifications` حتى لو التبديل مفعّل — البوابة مزدوجة (صلاحية + تبديل).
12. **حدّ المحاولات في verify vs عدّاد الحصة**: `attempts` على OtpCode يمنع القوة الغاشمة (حد 5)، منفصل تماماً عن حصص واتساب.
13. **فجوة محتملة**: لا يوجد throttle صريح على `/wa/webhook` POST سوى 120/دقيقة — يعتمد الأمان كلياً على HMAC؛ ضبط `WA_APP_SECRET` شرط تشغيل الويبهوك أصلاً (بدونه كل شيء يُرفَض).
14. **`Notification` (سجل الصادر) يكتبه Next.js**: للقراءة فقط من الـ API؛ منفصل عن `WaMessage` (سجل واتساب الأصلي في هذا الـ API) — قد يوجد ازدواج تسجيل بين النظامين لنفس الرسالة.
15. **تحويل Lead ينشئ مستخدماً بكلمة مرور**: `convert` يتطلّب `email` فريد + `password` (≥6) ويستدعي `TenantProvisioner` — أي فشل تزويد يترك Lead كما هو (لم يُوسَم WON إلا بعد نجاح التزويد).
