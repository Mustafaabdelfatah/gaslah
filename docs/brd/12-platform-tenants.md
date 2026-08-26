# BRD 12 — المنصة والمنشآت

> ملف استخراجي شامل لمجال **إدارة المنصة، المنشآت، اشتراكات المنصة، الفوترة، Dunning، الأجهزة، الشركاء، الكوبونات، إدارة المنشأة الذاتية، والتسجيل والتزويد**.
> لا يشمل: المدفوعات الإلكترونية والتسويات البنكية (ملف 05)، ولا محاسبة المنشأة الداخلية (ملف 08). محاسبة **دفاتر المنصة** نفسها تُذكر هنا لأنها جزء من عالم المنصة.
> يفترض أنك قرأت `00-overview-architecture.md`. تفاصيل استحقاقات `EntitlementResolver` (features/limits) موثّقة في **ملف 01** ويُشار إليها هنا عند الحاجة.

---

## 1. نظرة عامة (العوالم واللوحتان)

النظام SaaS متعدّد المستأجرين. توجد ثلاثة عوالم متمايزة، ولكل منها بوابة مصادقة مختلفة:

1. **عالم المنشأة (Tenant / staff):** موظفو المغسلة يعملون داخل منشأتهم فقط. توكن staff يحمل `orgId/branchId/role`. كل الكنترولرات تستخدم trait `ResolvesTenant` وتُقيّد كل استعلام على `orgId()`. الأدوار: `SUPER_ADMIN` / `BRANCH_MANAGER` / `CASHIER` / `RECEPTION` (موثّقة في ملف 01).
2. **عالم مالك المنصة (Platform owner panel — `/platform/*`):** لوحة المالك القديمة (cross-org). حارسها `assertPlatformOwnerRole()` (يتطلب أن يكون الدور الحيّ `OWNER`) أو `assertPlatformOwner()`. مبنية على `RequiresPlatformOwner`.
3. **عالم أدمن النظام (System-Admin console — `/admin/*`):** الكونسول الحديث الكامل. حارسه `assertSystemAdmin()` الذي يتطلب توكن من نوع `kind:platform` **و** يعيد التحقق من المستخدم الحيّ في قاعدة البيانات في كل طلب، مع بوابة صلاحيات دقيقة عبر `assertPlatformPermission($key)`.

### الفرق الجوهري بين اللوحتين (حارسان مختلفان)
- **`/platform/*`** — لا يملك خريطة صلاحيات دقيقة، لذلك كل عملية تُحرَس بـ `assertPlatformOwnerRole()` (المالك فقط) — لأن أي أدمن (حتى VIEWER) يحمل claim `isPlatformOwner`، وبدون هذه البوابة كان يمكن لـ VIEWER أن يصكّ توكن SUPER_ADMIN لمنشأة عبر الانتحال.
- **`/admin/*`** — يتطلب جلسة أدمن منفصلة (`kind:platform`) ويعيد التحقق من `isActive/isPlatformOwner` من قاعدة البيانات على كل طلب. هذا يعني أن توكن staff مسروق لا يصل لـ `/admin/*`، وتعطيل/تخفيض أدمن يسري **فوراً** (الحارس يقرأ الـ DB وليس claims قديمة من التوكن).

> **ملاحظة أمنية جوهرية:** claims التوكن هي لقطة قديمة من وقت الدخول. الحارسان في العالمين 2 و3 يعيدان قراءة `User` الحيّ لضمان أن الانتحال/التعطيل يسري لحظياً.

---

## 2. كيانات المنشأة (Organization, Branch)

### 2.1 Organization (جدول `Organization`)
منشأة المستأجر. يمتد `PrismaModel` (PK cuid نصّي، `$incrementing=false`). الحقول:

**حقول العمل الأساسية:**
- `id` (cuid), `name`, `slug` (= آخر 8 أحرف من الـ id، lowercase), `customDomain`
- `defaultCurrency` (افتراضي `SAR`), `taxRate` (float، افتراضي 15 — نسبة ضريبة القيمة المضافة لكل منطقة: السعودية 15، الإمارات 5…)
- `phone`, `email`, `address`, `crNumber` (السجل التجاري), `vatNumber` (الرقم الضريبي)
- `receiptFooter`, `receiptWidth` (int)
- `brandPrimary`, `brandAccent`, `logoUrl`
- `settings` (JSON، array cast — إعدادات المنشأة العامة)
- `createdAt`, `archivedAt` (nullable — للأرشفة الناعمة)

**حقول تحكّم المنصة (system-admin per-tenant controls) — كلها إضافية nullable:**
- `isSuspended` (bool) — إيقاف صارم للمنشأة مستقل عن حالة الاشتراك. عند `true` يصبح الحساب للقراءة فقط، وتُستثنى المنشأة من دورة Dunning.
- `featureOverrides` (JSON `{featureKey: bool}`) — إجبار خاصية معيّنة on/off لكل منشأة، فوق ما تمنحه الخطة. تُطبَّق فقط على المفاتيح **gated** (غير core).
- `maxBranchesOverride` (int nullable) — تجاوز حد الفروع من الخطة.
- `maxUsersOverride` (int nullable) — تجاوز حد المستخدمين من الخطة.
- `adminFollowUp` (bool) — علم متابعة CRM من الأدمن.
- `adminTags` (JSON array) — وسوم إدارية (≤20 وسم، ≤30 حرف لكل وسم).
- `accountCredit` (float) — رصيد دائن يُطبّق على فاتورة الاشتراك التالية (لا يقلّ عن 0).
- `payoutConfig` (JSON) — إعدادات التسويات البنكية (تُستهلك في ملف 05).

**العلاقات والنطاقات:**
- `branches()` = hasMany Branch.
- `platformSubscription()` = hasOne PlatformSubscription (واحد لكل منشأة).
- **`scopeTenantsOnly($query)`** — يستثني «org دفاتر المنصة» المحجوزة (`PlatformBooks::storedOrgId()`) من أي قائمة/عدّ مواجه للمستأجرين. ليس global scope — بحث by-id يظل يُرجعها (لأن PlatformBooks نفسه يحتاجها).

### 2.2 Branch (جدول `Branch`)
فرع تابع لمنشأة. الحقول:
- `id` (cuid), `orgId`, `name`, `code` (رمز الفرع، unique per `(orgId, code)`، uppercase)، الفرع الرئيسي دائماً `code = MAIN`
- `address`, `phone`
- `isActive` (bool cast)
- `createdAt`
- العلاقات: `org()` belongsTo Organization، `orders()` hasMany Order.

**قواعد الفروع:**
1. لا يمكن تعطيل آخر فرع نشط في المنشأة (`updateBranch` يرفض بـ 422).
2. رمز الفرع فريد داخل المنشأة (فحص عند الإنشاء والتعديل).
3. إضافة فرع جديد تخضع لحدّ الاشتراك (`assertBranchQuota`) وتتطلّب اشتراكاً نشطاً (`requireActiveSubscription`).

---

## 3. كيانات اشتراك المنصة

### 3.1 PlatformPlan (جدول `PlatformPlan`)
الخطة (الباقة) التي تبيعها **المنصة** للمنشآت (مميّزة عن `SubscriptionPlan` الذي هو باقة عميل داخل المنشأة). لا يوجد `updatedAt`، `timestamps=false`. الحقول:
- `id` (cuid), `name`, `nameEn`
- `monthlyPrice` (float), `yearlyPrice` (float)
- `maxBranches` (int)، `maxUsers` (int)
- `features` (Postgres `text[]` عبر `PgTextArray`) — نصوص عرض تسويقية للخطة
- `featureKeys` (Postgres `text[]`) — مفاتيح الاستحقاق الفعلية (من `FeatureRegistry`) التي تُفعِّلها الخطة
- `isPopular` (bool), `sortOrder` (int), `isActive` (bool)
- العلاقة: `subscriptions()` hasMany PlatformSubscription.

### 3.2 PlatformSubscription (جدول `PlatformSubscription`)
اشتراك منشأة واحدة في المنصة. **صف واحد لكل منشأة**. لا يوجد `updatedAt`. الحقول:
- `id` (cuid), `orgId`, `planId`
- `cycle` — `MONTHLY` أو `YEARLY`
- `status` — enum منطقي: **`TRIAL` / `ACTIVE` / `PAST_DUE` / `CANCELLED`**
- `price` (float) — السعر الفعلي المدفوع (قد يكون سعراً يدوياً بعد كوبون؛ في TRIAL = 0)
- `startedAt`, `currentPeriodEnd` (datetime)
- `cancelAtPeriodEnd` (bool), `canceledAt` (datetime nullable)
- العلاقات: `plan()`, `organization()`.

**حالات الاشتراك (enum منطقي) بالتفصيل:**
| الحالة | المعنى | نشط للكتابة؟ |
|--------|--------|-------------|
| `TRIAL` | تجربة مجانية، `price=0` | نعم (طالما `currentPeriodEnd` مستقبلي) |
| `ACTIVE` | مدفوع ونشط | نعم (طالما ضمن الفترة) |
| `PAST_DUE` | تجاوز تاريخ التجديد ولم يُسدَّد | لا (للقراءة فقط) |
| `CANCELLED` | مُلغى | لا |
| *(no row)* | لا اشتراك — grandfathered | نعم، كل الخصائص + حدود لانهائية (إلا إن كانت المنشأة مُوقفة) |

**حالة مشتقّة `EXPIRED`:** ليست قيمة مخزّنة. عندما تكون `TRIAL`/`ACTIVE` لكن `currentPeriodEnd` مضى — تُعرَض كـ `EXPIRED` في كل واجهات العرض (`PlatformTenantController`, `OrgSubscriptionController`, `EntitlementResolver`). القيمة الخام تبقى في `rawStatus`.

### 3.3 OrgAddOn (جدول `OrgAddOn`)
خاصية مدفوعة إضافية مُفعَّلة لمنشأة **فوق خطتها** (مثل `delivery`). صف واحد لكل `(orgId, key)`. `timestamps=false` (الجدول به `activatedAt/expiresAt` فقط، لا created/updated). الحقول:
- `id`, `orgId`, `key`, `isActive` (bool), `priceMonthly` (float), `activatedAt`, `expiresAt`.
- مفاتيح الإضافات المعروفة (في `PlatformTenantAdminController`): `delivery` (التوصيل)، `portal_offers` (عروض بوابة العملاء)، `supplier_market` (سوق الموردين).
- يدخل ضمن حساب الاستحقاقات: `featureKeys الخطة ∪ مفاتيح الإضافات النشطة غير المنتهية` (تفاصيل في ملف 01).

### 3.4 PlatformCoupon (جدول `PlatformCoupon`)
كوبون خصم على اشتراكات المنشآت. يُستهلك في `AdminTenantController::updateSubscription` عند تمرير `couponCode`. الحقول:
- `id`, `code` (uppercase، فريد، regex `^[A-Za-z0-9_-]+$`, 2..40)
- `type` — **`PERCENT` / `FIXED` / `FREE_MONTHS`** (`PlatformCoupon::TYPES`)
- `value` (float)، `maxRedemptions` (int nullable)، `redemptions` (int، عدّاد مُستهلَك)
- `appliesToPlanId` (nullable — يقيّد الكوبون بخطة)، `expiresAt` (nullable)، `isActive` (bool)، `note`
- `createdAt`, `updatedAt`.

**أنواع الكوبون وأثرها (`effect(float $price)`):**
- `PERCENT` → السعر الجديد = `price × (1 − value%/100)` (value محدود 0..100).
- `FIXED` → السعر الجديد = `max(0, price − value)`.
- `FREE_MONTHS` → لا يغيّر السعر؛ يُرجع `extraMonths = (int) value` تُضاف إلى نهاية الفترة.
- يُرجع `{price, extraMonths, discount}` حيث `discount = price − newPrice`.

**قابلية الاستخدام (`isRedeemable(?planId)`):** نشط، وغير منتهٍ، وعدّاد الاستهلاك < الحد الأقصى، والخطة مطابقة إن كان مقيّداً بخطة.
**الاستهلاك (`redeem()`):** تحديث ذرّي مشروط `redemptions < maxRedemptions` (لا يتجاوز الحد أبداً حتى تحت التزامن).

---

## 4. كيانات المنصة الأخرى

### 4.1 PlatformConfig (جدول `PlatformConfig`)
مخزن key/value مفرد (singleton) بقيم JSON، PK نصّي = المفتاح، لا يوجد `createdAt` (يوجد `updatedAt`). دوال `get(key, default)` / `put(key, value)` (upsert). مفاتيح مستخدَمة:
- `platform` → إعدادات مسطّحة قديمة (يقرأها `PlatformConfigStore`): `trialDays`, `allowPublicSignup`, `sellerName/sellerVat/sellerCr/sellerAddress`.
- `platform.settings` → الإعدادات المُجمَّعة (يقرأها `PlatformSettings`).
- `platform.dunning` → سياسة Dunning.
- `platform.customRoles` → أدوار الأدمن المخصّصة.
- `platformBooks` → `{orgId}` org دفاتر المنصة المحجوزة.

### 4.2 PlatformDevice (جدول `PlatformDevice`)
كتالوج أجهزة تبيعها المنصة (جهاز نقاط بيع POS، طابعة فواتير…). الحقول: `id`, `name`, `sku`, `price` (float — سعر الوحدة **شامل الضريبة**)، `isActive`, `sortOrder`, `createdAt`, `updatedAt`. يُستخدم لتعبئة سطور فاتورة بيع جهاز مسبقاً.

### 4.3 DeviceSale (جدول `DeviceSale`)
فاتورة ضريبية ZATCA لبيع أجهزة لمنشأة أو مشترٍ خارجي. سلسلة ICV/PIH خاصة ببادئة **`DEV-`**، منفصلة عن فواتير الاشتراك. لا يوجد `updatedAt`. الحقول:
- `id`, `orgId` (nullable — مشترٍ خارجي)، `invoiceNo`
- `buyerName`, `buyerVat`, `sellerName`, `sellerVat`
- `items` (JSON array — لقطة السطور `[{name, qty, unitPrice, lineTotal}]`)، `notes`
- `subtotal`, `vat`, `total` (float)
- `paymentMethod`, `bankName`, `transferRef`, `gatewayName`
- `icv` (int), `pih`, `hash`, `qr`
- `status` — **`DRAFT` / `ISSUED`**
- `confirmedAt`, `confirmedById`, `issuedAt`, `createdAt`.

### 4.4 PlatformPartner (جدول `PlatformPartner`)
شريك مؤسِّس — ملكية أسهم + حصة أرباح على دفاتر المنصة. الحقول: `id`, `name`, `role`, `email`, `ownershipPercent` (float)، `joinedAt`, `isActive` (bool)، `notes`, `createdAt`, `updatedAt`.

### 4.5 PlatformPartnerDistribution (جدول `PlatformPartnerDistribution`)
توزيع نقدي مدفوع لشريك مقابل حصته. لا `updatedAt`. الحقول: `id`, `partnerId`, `amount` (float)، `date`, `note`, `recordedById`, `createdAt`.

### 4.6 PlatformExpense (جدول `PlatformExpense`)
مصروف تشغيلي للمنصة (تسويق، رواتب، استضافة…) لقائمة دخل الـ SaaS. لا `updatedAt`. الحقول: `id`, `date`, `category`, `amount` (float)، `note`, `createdById`, وحقول تمويل الشريك الإضافية: `paidByPartnerId` (nullable — الشريك الذي موّل المصروف)، `reimbursedAt` (nullable)، `reimbursedById` (nullable).

### 4.7 PlatformEvent (جدول `PlatformEvent`)
سجل أحداث دورة حياة الاشتراك. `timestamps=false`. الحقول: `id`, `orgId`, `type`, `planName`, `cycle`, `monthly` (float)، `amount` (float)، `createdAt` (column default).
**أنواع الأحداث (`type`):** `SIGNUP`, `TRIAL_START`, `TRIAL_CONVERT`, `RENEW`, `PLAN_CHANGE`, `EXTEND`, `CANCEL_SCHEDULED`, `REACTIVATE`, `SUSPEND`, `EXPIRE`.
**تصنيف MRR:** churn = `{CANCEL_SCHEDULED, SUSPEND, EXPIRE}`؛ new-MRR = `{SIGNUP, TRIAL_CONVERT}`.

### 4.8 PlatformAnnouncement (جدول `PlatformAnnouncement`)
لافتة بث platform → tenant تظهر داخل لوحات المنشآت. الحقول: `id`, `title`, `body`, `level` (**`INFO`/`WARNING`/`CRITICAL`**)، `orgId` (nullable — **null يعني كل المستأجرين**، وقيمة محدّدة تُقصِره على منشأة واحدة)، `isActive`, `startsAt`, `endsAt`, `createdById`, `createdAt`, `updatedAt`. علاقة `organization()`.

### 4.9 OrgDocument (جدول `OrgDocument`)
مستند أعمال (سجل تجاري، شهادة ضريبة، رخصة…) مرفق بملف المنشأة. لا `updatedAt`. الحقول: `id`, `orgId`, `name`, `path`, `mimeType`, `size` (int)، `createdAt`. علاقة `org()`.

### 4.10 SubscriptionInvoice (جدول `SubscriptionInvoice`)
فاتورة ضريبية ZATCA يصدرها مشغّل الـ SaaS مقابل اشتراك منشأة. سلسلة ICV/PIH ببادئة **`SUB-`**. لا `updatedAt`. الحقول:
- `id`, `orgId`, `subscriptionId`, `chargeId`, `invoiceNo`
- `sellerName`, `sellerVat`, `buyerName`, `buyerVat`
- `planName`, `cycle`
- `subtotal`, `vat`, `total` (float)
- `paymentMethod`, `bankName`, `transferRef`, `gatewayName`
- `icv` (int), `pih`, `hash`, `qr`
- `status` — **`DRAFT` / `ISSUED`**
- `confirmedAt`, `confirmedById`, `issuedAt`, `createdAt`.

---

## 5. التسجيل والتزويد (Signup + TenantProvisioner)

هناك مسارَان يشتركان في **نفس المنطق** (منشأة + فرع MAIN + أدمن + كتالوج مبدئي + تجربة 14 يوماً):

### 5.1 التسجيل الذاتي العام — `POST /signup` (`SignupController`)
- **عام (خارج `auth.api`)**، محدود بـ `throttle:signup` لمنع التزويد الآلي للمنشآت الوهمية.
- **البوابة الأولى (قبل التحقّق):** `abort_unless(PlatformConfigStore::allowPublicSignup())` — إن كان التسجيل مغلقاً في الكونسول يُرفض بـ 403. التحقّق **قبل** validation عمداً: كي لا يستطيع مجهول اختبار وجود بريد عبر قاعدة `Rule::unique` بينما التسجيل مغلق.
- **المدخلات:** `orgName`, `adminName`, `email` (فريد في جدول User)، `phone` (اختياري)، `password` (حسب `PlatformSettings::passwordRules(true)`).
- **العملية (transaction كاملة، أي فشل يُرجِع كل شيء):**
  1. إنشاء `Organization` (`defaultCurrency=SAR`, `taxRate=15`)، ثم ضبط `slug` = آخر 8 أحرف من الـ id.
  2. إنشاء الفرع الرئيسي `Branch` (`code=MAIN`, name «الفرع الرئيسي»).
  3. إنشاء `User` بدور `SUPER_ADMIN` وكلمة مرور `password_hash(..., PASSWORD_BCRYPT)` (تكتب `$2y$` يقبلها `password_verify()` في تسجيل الدخول).
  4. ربط `UserBranch` بدور `SUPER_ADMIN`.
  5. كتالوج مبدئي ثلاثي الأبعاد (فئة × منتج × نوع خدمة). في `SignupController` يشمل 4 فئات (ثياب رجالية، نسائية، مفروشات وسجاد، **أحذية** — الأخيرة `shoesOnly` بنوع WASH فقط)؛ لكل خلية سعر عادي + إكسبريس (`expressSurcharge = normal × 0.5`، IRON = ×0.6، WASH_IRON = ×1.4).
  6. تجربة مجانية على أرخص خطة نشطة (`orderBy sortOrder`)، `status=TRIAL`, `price=0`, مدة = `PlatformConfigStore::trialDays()` (افتراضي 14). تُسجَّل `PlatformEvent type=TRIAL_START`.
- **الخرج:** توكن دخول بنفس شكل `AuthController::login` (claims: `userId/orgId/branchId/role/isPlatformOwner=false/platformRole=null`) + بيانات المستخدم، بحالة 201.

### 5.2 `TenantProvisioner::provision(array $attrs)` — الخدمة المشتركة
تُعيد `{org, branch, user}` وتُنفّذ نفس الخطوات، لكن كتالوجها المبدئي **3 فئات فقط** (بدون أحذية). تقبل `planId` اختياري (إن غاب: أرخص خطة نشطة). تُستخدم من:
- `AdminTenantController::store` (إضافة منشأة من الكونسول).
- `AdminLeadController::convert` (تحويل عميل محتمل → منشأة).

> **فرق دقيق (فجوة):** `SignupController` يكرّر منطق التزويد بنفسه (بكتالوج 4 فئات + أحذية) بدلاً من استدعاء `TenantProvisioner` (3 فئات). المنطق مكرّر ومتباين قليلاً في محتوى الكتالوج.

### 5.3 تحويل العميل المحتمل (Lead → Tenant)
`AdminLeadController::convert(id)`: يرفض إن كان `convertedOrgId` مضبوطاً مسبقاً (422). يستدعي `provision()` باسم `businessName` من الـ Lead وبيانات أدمن جديدة، ثم يضبط `stage=WON`, `convertedOrgId`, `wonAt`. يُدقَّق (`auditAdmin CONVERT`). مُحرَّس بـ `manage_leads`.

---

## 6. اشتراك المنصة وفوترته

### 6.1 إدارة الخطط
- **لوحة المالك (`/platform/plans`، `PlatformPlanController`):** index (كل الخطط + عدّ اشتراكات حيّ)، store، update. مُحرَّس بـ `assertPlatformOwnerRole` (مالك فقط).
- **الكونسول (`/admin/plans`، `AdminPlanController`):** index (كل خطة + إحصاءات المشتركين/النشطين/MRR + كتالوج الخصائص)، store، update. القراءة تتطلب جلسة أدمن حيّة؛ الكتابة تتطلّب صلاحية **`manage_plans`**. الخطط **لا تُحذف نهائياً** — تُتقاعد عبر `isActive=false`.
- **حساب MRR للخطة:** فقط الاشتراكات `ACTIVE`، MONTHLY بسعر `monthlyPrice` وYEARLY بسعر `yearlyPrice/12`.
- **تحديث جزئي (update):** يُعاد كتابة الحقول المُرسَلة فقط؛ إغفال حقل اختياري (featureKeys/features/isActive) لا يمحو القيمة المخزّنة ولا يتقاعد الخطة صامتاً. `featureKeys` تُتحقَّق ضد `FeatureRegistry::gatedKeys()`.

### 6.2 ضبط اشتراك المنشأة (الكونسول) — `AdminTenantController::updateSubscription`
مُحرَّس بـ **`manage_subscriptions`**. المدخلات: `planId` (موجود)، `status` (`TRIAL/ACTIVE/PAST_DUE/CANCELLED`)، `cycle` (`MONTHLY/YEARLY`)، `currentPeriodEnd` (اختياري — افتراضياً +شهر أو +12 شهراً)، `cancelAtPeriodEnd`، `customPrice` (سعر يدوي)، `couponCode`.
- السعر = `customPrice` إن وُجد، وإلا سعر الخطة للدورة. في `TRIAL` يُصفَّر السعر إلى 0.
- **تطبيق الكوبون:** إن وُجد `couponCode` وكان `isRedeemable(planId)`: يُطبَّق `effect()` (يعدّل السعر و/أو يمدّد الفترة بـ FREE_MONTHS)، ثم `redeem()` ذرّياً. فشل أي منهما → 422 وكوبون غير مُستهلَك.
- يُسجَّل `PlatformEvent` (`SIGNUP` إن كان جديداً وإلا `PLAN_CHANGE`) بـ MRR شهري مبني على السعر الفعلي. يُدقَّق `auditAdmin UPDATE Subscription`.

### 6.3 ضبط اشتراك المنشأة (لوحة المالك) — `PlatformTenantAdminController::updateSubscription`
مُحرَّس بـ `assertPlatformOwnerRole`. أبسط: `planId/status/cycle/currentPeriodEnd`، السعر يُعاد حسابه من الخطة للدورة (لا سعر يدوي، لا كوبون). `updateOrCreate` على `orgId`.

### 6.4 بدء/إعادة التجربة — `AdminTenantController::startTrial`
مُحرَّس بـ `manage_subscriptions`. المدة = `PlatformConfigStore::trialDays()`، الخطة = المُرسَلة أو أرخص خطة نشطة، `status=TRIAL`, `price=0`, `cycle=MONTHLY`. يُسجَّل `TRIAL_START`.

### 6.5 فوترة الاشتراك — نموذج الخطوتين (`SubscriptionInvoicer`)
فوترة ضريبية ZATCA على خطوتين، والمشغّل هو البائع. السعر يُعامَل **شاملاً للضريبة** (VAT مستخرَجة بنسبة 15/115):

1. **`quote()` — مسوّدة (عرض سعر مبدئي):** تنشئ صف `SubscriptionInvoice` بحالة `DRAFT`. **لا** يُستهلَك سلوت ICV/PIH، **لا** ترحيل محاسبي. ليست مستنداً ضريبياً بعد ويمكن حذفها بحرّية. تلتقط بيانات الدفع (كيف يُتوقّع أن تدفع المنشأة) كي يراها المحاسب.
2. **`confirm()` — اعتماد:** بعد أن يؤكّد المحاسب استلام الدفع: يُخصَّص سلوت السلسلة `SUB-` التالي (`icv+1`، `pih = hash السابق`، بدءاً من `GENESIS_PIH`)، يُبنى canonical + hash SHA256 + QR (tags 1..6)، تُقلَب الحالة إلى `ISSUED`، ويُرحَّل الإيراد إلى دفاتر المنصة — **كل ذلك في transaction واحدة** فلا توجد فاتورة معتمَدة بلا قيد محاسبي مطابق (والعكس). هنا يصبح الصف فاتورة ZATCA غير قابلة للتعديل.

**التحكّم بالتزامن:**
- **compare-and-swap ذرّي:** التحديث مشروط بـ `where status = DRAFT`؛ اعتماد ثانٍ متزامن (نقرة مزدوجة/محاسبان) يؤثّر في 0 صف ويُرفض بـ 409 بدلاً من الكتابة فوق الفائز.
- **حلقة إعادة (8 محاولات):** عند تصادم unique على `icv/invoiceNo` تُعاد الحسبة بعد `usleep`.
- السلسلة تتقدّم فوق صفوف `ISSUED` فقط (المسوّدات لم تحمل سلوتاً أبداً).

3. **`issue()`:** quote + confirm في نداء واحد، **للـ backfill التاريخي فقط** (فواتير لرسوم PAID مُحصَّلة سابقاً — لا مرحلة مسوّدة). ملفوف في transaction: فشل confirm يُرجِع المسوّدة أيضاً حتى لا تبقى مسوّدة يتيمة بـ chargeId (وإلا تُوسَم الرسوم «مُفوترة» وتُتجاوَز للأبد).

### 6.6 الترحيل لدفاتر المنصة (`PlatformBooks`)
المنصة تمسك دفاترها المزدوجة الخاصة على **Organization محجوزة** (id في `PlatformConfig('platformBooks')`، اسمها «دفاتر المنصّة»). هذا يمنح كونسول الأدمن نفس ورش المحاسبة/التقارير كأي منشأة، وتُستثنى هذه الـ org من كل قائمة مواجهة للمستأجرين.
- **مخطّط حسابات خاص:** حساب SALES يُعاد تسميته «إيرادات الاشتراكات» + حساب مخصّص `DEVICE_SALES` (كود 4120) + حساب `PARTNER_DRAWINGS` (كود 3030، contra-equity). Idempotent.
- **`postRevenue`** (اشتراك): Dr BANK إجمالي / Cr SALES صافي / Cr VAT_PAYABLE ضريبة. `refType=SubscriptionInvoice`, `source=PAYMENT`. Idempotent per refId.
- **`postDeviceSale`:** Dr BANK / Cr DEVICE_SALES صافي / Cr VAT_PAYABLE. `refType=DeviceSale`.
- **`postPartnerDistribution`:** Dr PARTNER_DRAWINGS / Cr BANK. `refType=PlatformPartnerDistribution`, `source=MANUAL`.
- **`postExpense`:** Dr OPEX / Cr CASH (كامل المبلغ). `source=EXPENSE`, `refType=PlatformExpense`.

> **قيد enum المصدر:** لا يوجد `SUBSCRIPTION` في `JournalSource`؛ لذلك دفع الاشتراك يُميَّز بـ `source=PAYMENT, refType=SubscriptionInvoice` (راجع قيود المشروع في CLAUDE.md).

### 6.7 عرض الفواتير للأدمن — `AdminInvoiceController`
- **index/show:** قراءة مُحرَّسة بـ **`view_finance`**. الإجماليات تُحسَب على `ISSUED` فقط (المسوّدات ليست إيراداً).
- **store (مسوّدة):** مُحرَّس **`manage_subscriptions`**. تحديد الإجمالي: (1) مبلغ يدوي، وإلا (2) سعر الخطة للدورة، وإلا (3) سعر اشتراك المنشأة الحالي. `paymentMethod` من `CASH/BANK_TRANSFER/GATEWAY` (bankName/transferRef مطلوبة للتحويل، gatewayName للبوابة).
- **confirm:** مُحرَّس **`manage_accounting`** (إجراء اعتراف بالإيراد). يستدعي `confirm()`.
- **destroy:** حذف مسوّدة فقط (409 إن كانت معتمَدة).

---

## 7. Dunning (`DunningService`)

أتمتة تذكير/تصعيد لاشتراكات المنشآت. **دورة يومية واحدة** تنفّذ سياسة المشغّل: تذكير قبل التجديد، توليد فاتورة تجديد + الهبوط إلى `PAST_DUE` في تاريخ الاستحقاق، تذكير مستمر أثناء التأخّر، ثم التعليق بعد فترة السماح. القنوات: WhatsApp و/أو Email. **لا سحب تلقائي للأموال** (Moyasar غير مُرمَّز/tokenized) — المنشأة تدفع عبر الفاتورة/الرابط.

### 7.1 الإعدادات (`platform.dunning`)
افتراضياً: `enabled=false`, `remindDaysBefore=[3]`, `remindDaysAfter=[3,7]`, `graceDays=14`, `channels={whatsapp:true, email:true}`.

### 7.2 خطوات الدورة (`run()`)
لو `enabled=false` تُرجع summary صفرية. تجلب الاشتراكات `{ACTIVE, TRIAL, PAST_DUE}` ذات `currentPeriodEnd`، مستثنيةً org دفاتر المنصة. لكل اشتراك (تُتخطّى المنشآت المُوقفة `isSuspended`):
1. **تذكير ما قبل التجديد:** إن كان `daysUntil ∈ remindDaysBefore` والحالة `ACTIVE/TRIAL` → حدث `trialEnding` (لو TRIAL) أو `dunning`.
2. **بلوغ الاستحقاق (`daysUntil ≤ 0`) والحالة `ACTIVE/TRIAL`:** إنشاء فاتورة تجديد مسوّدة (`invoicer->quote`) مرة واحدة لكل فترة (مفتاح `invoice:{periodKey}`)، ثم تحويل الحالة إلى **`PAST_DUE`**، وإرسال تذكير `dunning`.
3. **التأخّر (`PAST_DUE`):** إن كان `overdue ∈ remindDaysAfter` → تذكير `dunning`. وإن `overdue ≥ graceDays` (ولم يُرسَل سابقاً) → **تعليق** (`isSuspended=true`) + تذكير `suspended`.

### 7.3 «مرة واحدة لكل مرحلة»
كل (منشأة، مرحلة، فترة) يُطلَق **مرة واحدة فقط**، مُتتبَّعاً في `AuditLog` (`action=DUNNING`, `entity=Subscription`, `entityId=orgId`, `after.key`). الدالة `sent()` تفحص وجود المفتاح؛ `mark()` تكتبه (عبر `forceCreate` لأن AuditLog جدول غير قابل للكتابة الجماعية). أخطاء إرسال WhatsApp مُلتقَطة (best-effort) كي لا تُجهِض الدورة كلها.

### 7.4 الكونسول — `AdminDunningController`
مُحرَّس **`manage_subscriptions`**: index (السياسة + آخر 50 نشاطاً)، update (حفظ السياسة)، run (تشغيل دورة الآن، يُرجع summary: processed/reminders/invoices/lapsed/suspended). يُدقَّق.

---

## 8. إدارة المنصة (اللوحتان، stats، الدليل، الانتحال، الأجهزة، الشركاء)

### 8.1 لوحة المالك `/platform/*` (كل عملية `assertPlatformOwnerRole`)
- **`GET /platform/stats` (`PlatformStatsController`):** KPIs عبر المنشآت (tenants, activeSubs, customers, orders, branches, **MRR/ARR**)، شلّال حركة MRR الشهري من سجل الأحداث (new/expansion/contraction/churn)، مقاييس التجربة والتحويل (`conversionRate`)، إيرادات اليوم/الشهر/الكل (من `OnlineCharge purpose=SUBSCRIPTION status=PAID`)، signups-by-month + revenue-by-month (آخر 12)، توزيع الخطط، آخر 14 حدثاً. MRR = الاشتراكات `ACTIVE` غير المنتهية فقط، YEARLY = `price/12`.
- **`GET /platform/tenants` + `/{id}` (`PlatformTenantController`):** دليل المنشآت cross-org مع عدّ الفروع/المستخدمين/الطلبات وحالة الاشتراك (المنتهية تُعرَض `EXPIRED`)؛ التفصيل يشمل المستخدمين والإيراد والأحداث و`atRisk` (نشط بلا طلبات آخر 30 يوماً). يستثني org دفاتر المنصة عبر `tenantsOnly()`.
- **`/platform/plans` (`PlatformPlanController`):** أعلاه.
- **`GET /platform/users` (`PlatformUserController`):** دليل أدمن المنصة (للقراءة فقط) — الأدوار الفارغة القديمة تُعامَل OWNER.
- **`/platform/affiliates`, `/platform/leads`** (خارج نطاق هذا الملف تفصيلياً).
- **`PlatformExtraController` (`/platform/*` الفرعية):** الإعلانات (CRUD broadcast)، سجل النشاط (`/platform/activity` — قيود التدقيق بأيدي أدمن المنصة)، مستخدمو منشأة واحدة (`/platform/tenants/{orgId}/users`).
- **`PlatformTenantAdminController` (`/platform-admin`):** تحكّم لكل منشأة (انتحال، تعديل اشتراك، تبديل الإضافات، طرق التوصيل).

### 8.2 الانتحال (Impersonation)
مساران، كلاهما **OWNER فقط**:
- **`POST /platform/impersonate/{orgId}` (`PlatformTenantAdminController`):** يختار مستخدماً نشطاً غير-أدمن من المنشأة (مفضّلاً SUPER_ADMIN)، يصكّ توكن staff بنفس شكل `/login` موسوماً `impersonatedBy` بالمالك. توكن المالك لا يُمَس. يُخزَّن jti التوكن في cache (مفتاح لكل مالك، حتى 20 توكناً) كي يقتله `stopImpersonation` من الخادم.
- **`POST /platform/impersonate/stop`:** يُبطِل (revoke عبر `TokenDenylist`) كل توكنات الانتحال الحيّة للمالك — فلا يعيش توكن SUPER_ADMIN 12 ساعة بعد الخروج على جهاز مشترك.
- **`POST /admin/tenants/{orgId}/impersonate` (`AdminTenantDetailController`):** توكن **قصير العمر 30 دقيقة** بـ claim `imp` (معرّف الأدمن المنتحِل)، `isPlatformOwner=false` دائماً. يتخطّى المستخدمين الذين هم أنفسهم أدمن منصة (وإلا يعيد الـ middleware ختمه كـ isPlatformOwner فيتحوّل session مقيّد إلى session منصة كامل). يُدقَّق `IMPERSONATE`.

### 8.3 الأجهزة (`AdminDeviceController` + `AdminDeviceSaleController` + `DeviceInvoicer`)
- **الكتالوج (`/admin/devices`):** قراءة لأي أدمن؛ الكتابة `manage_subscriptions`.
- **مبيعات الأجهزة (`/admin/device-sales`):** نفس دورة الخطوتين كفواتير الاشتراك (`quote` DRAFT → `confirm` ISSUED)، سلسلة **`DEV-`**. المشتري إمّا منشأة (`orgId`) أو مشترٍ خارجي مُسمّى. الأسعار شاملة للضريبة (VAT 15/115). القراءة `view_finance`، الإنشاء `manage_subscriptions`، الاعتماد `manage_accounting`، الحذف (DRAFT فقط) `manage_subscriptions`. الإيراد يُرحَّل عبر `postDeviceSale` لحساب DEVICE_SALES المنفصل. يُمنَع إصدار فاتورة للمنصة نفسها (422).

### 8.4 الشركاء (`AdminPartnerController`)
**حسّاس:** كل عملية (حتى القراءة) مُحرَّسة بـ **`manage_partners`** — نسب الملكية وحصص الأرباح لا يجب أن يراها موظفو المنصة العاديون. الاستثناء الوحيد `options()` (أسماء الشركاء فقط، لا مال/ملكية) مُحرَّس بـ `manage_accounting` لنموذج إدخال المصروف.
- **index/overview:** يحسب حصة الشريك من صافي دخل دفاتر المنصة (`AccountingReports::incomeStatement`) شهرياً وكلّياً = `ownershipPercent/100 × netIncome`. `netOwed = allTimeShare + outstandingReimbursement − totalDistributed`.
- **store/update:** سقف ملكية إجمالي قابل للضبط (افتراضي 100%) على الشركاء **النشطين**، مع قفل advisory (`pg_advisory_xact_lock`) حول القراءة+الكتابة فلا يتجاوز إنشاءان متزامنان السقف. إعادة تفعيل شريك (`isActive=true`) تُعيد فحص السقف. شريك غير نشط يساهم بـ 0.
- **توزيعات (`/distributions`):** الصف + القيد ذرّيان — التوزيع نقد فعلي يغادر، فتسجيله بلا قيد Dr Drawings/Cr Bank يُبقي الميزانية تبالغ في النقد.
- **مصروفات ممولة من شريك (`/expenses/outstanding` + `/reimburse`):** سداد ذرّي (compare-and-swap على `reimbursedAt`) — أول POST فقط ينجح، الثاني يُرفض 409. يُرفض سداد مصروف غير ممول من شريك (422).

### 8.5 تحكّم إضافي بالمنشأة (`AdminTenantController` + `AdminTenantDetailController`)
- **الاستحقاقات (`updateEntitlements`، `manage_tenants`):** `featureOverrides` (مفاتيح gated فقط، تُقسَر bool) + `maxBranchesOverride/maxUsersOverride`.
- **التعليق (`suspend`، `manage_tenants`):** `isSuspended` مستقل عن الاشتراك؛ يسجّل SUSPEND/REACTIVATE.
- **الملف التجاري (`updateProfile`):** name/CR/VAT/phone/email/address/**taxRate** (نسبة ضريبة لكل منطقة).
- **متابعة/تحكّم (`AdminTenantDetailController`، `manage_tenants`):** ملاحظات CRM (`storeNote/toggleNote/deleteNote`)، علم/وسوم (`updateMeta`)، رسالة موجَّهة (`message` — إعلان داخل اللوحة + WhatsApp اختياري لأدمن المنشأة).
- **`extend` (تمديد الفترة):** مُحرَّس **`manage_subscriptions`** (منح وقت اشتراك قرار فوترة، لا يكفيه `manage_tenants` الذي تملكه SUPPORT). لو كانت الحالة `PAST_DUE` تعود `ACTIVE`.
- **`applyCredit` (رصيد دائن):** مُحرَّس **`manage_accounting`** (مال حقيقي مقابل فواتير مستقبلية). لا يقلّ الرصيد عن 0.
- **الأرشفة/التصدير (`AdminTenantDataController`):** `export` (تصدير PII bundle) مُحرَّس **OWNER فقط** (`assertPlatformOwnerRole`)، مع سقف 10آلاف عميل و**إفصاح صريح** عند البتر (`customersTruncated/exportNote`). `archive/unarchive` مُحرَّسان `manage_tenants` (أرشفة ناعمة = `archivedAt` + تعليق). **لا حذف نهائي** (خطر cascade على DB مشتركة). المستندات (`AdminDocumentController`) تُخدَم عبر **رابط موقّع مؤقّت** (12 ساعة) والتوقيع هو التفويض.

### 8.6 مركز الإعدادات (`AdminConfigController`)
**OWNER فقط** (`assertOwner`) — حتى حامل `manage_config` يُرفض ما لم يكن دوره OWNER. يجمع الإعدادات المسطّحة القديمة (`PlatformConfigStore`) والمُجمَّعة (`PlatformSettings`: general/security/health/tenants/billing/messaging/tenantHealth/notifications/whatsapp/accounting/partners/support/announcements/content/marketing). الحفظ الجزئي = دمج (nulls تُسقَط). typed getters تُغذّي الأسلاك (TTL الجلسة، سياسة القفل، سياسة كلمة المرور، عتبات الصحة، تسعير التسويات).

---

## 9. إدارة المنشأة الذاتية

### 9.1 مستخدمو المنشأة (`OrgUserController`, `/org/users`)
كل عملية مُقيّدة على منشأة المتصل. الإدارة لـ `SUPER_ADMIN`/`BRANCH_MANAGER` فقط (`authorizeManager`)، والـ BRANCH_MANAGER محدود بفروعه.
- **الأدوار على `UserBranch`** (دور لكل فرع)؛ `User.role` يعكس أعلى دور امتيازاً (لإبقاء تدفّق الدخول). ترتيب الامتياز: `RECEPTION(1) < CASHIER(2) < BRANCH_MANAGER(3) < SUPER_ADMIN(4)`. الصلاحيات الافتراضية لكل دور في `ROLE_PERMISSIONS`؛ التجاوزات لكل مستخدم تُخزَّن JSON في `Setting` (مفتاح `user.permissions:{userId}`) — **لا تغيير schema**.
- **CRUD:** index (مع بحث + تجاوزات)، branches، roles (كتالوج الأدوار + الصلاحيات)، store، update، destroy.
- **`assertRoleCeiling` (منع التصعيد):** مدير لا يُعيّن أبداً دوراً أعلى من دوره (BRANCH_MANAGER لا يصكّ/يرقّي إلى SUPER_ADMIN).
- **حماية آخر SUPER_ADMIN:** لا يمكن تعطيل/تخفيض آخر SUPER_ADMIN نشط في المنشأة (`isLastActiveSuperAdmin`، يرفض 422).
- **soft delete:** `destroy` يضبط `isActive=false` فقط (لا حذف صلب لبيانات مشتركة). لا يمكن تعطيل حسابك الخاص، ولا مستخدم `isPlatformOwner`.
- **الحدود:** `store` يتطلّب اشتراكاً نشطاً (`requireActiveSubscription`) + `assertUserQuota` (المستخدمون النشطون فقط يستهلكون مقعداً؛ المُعطَّلون يحتفظون بروابط UserBranch دون استهلاك مقعد).

### 9.2 الفروع + الأداء + التكاليف (`OrganizationController`)
كل شيء مُحرَّس بـ `SUPER_ADMIN`/`BRANCH_MANAGER`؛ **طفرات الرواتب SUPER_ADMIN فقط**.
- **CRUD الفروع (`/organization/branches`):** إضافة تخضع لـ `requireActiveSubscription` + `assertBranchQuota`؛ لا تعطيل آخر فرع نشط؛ رمز فريد.
- **أداء الفروع (`branchPerformance`):** إيراد/طلبات/AOV/محصّل/مستحق/مصروفات/صافي مساهمة/موظفون/ورديات/فروق نقدية، مع مقارنة الفترة السابقة. قواعد الإسناد مُفصَحة (إيراد الفرع = Σ grandTotal لغير الملغى؛ المحصّل = Σ Payment عبر Order.branchId؛ إلخ).
- **أداء الموظفين (`employeePerformance`):** مبيعات/محصّل/ورديات/ساعات/فروق/مصروفات مُنشأة/تكلفة شهرية/تكلفة الفترة/نسبة الإيراد للتكلفة. `periodCost = monthlyCost × periodDays/30`.
- **تكاليف الموظف (`setEmployeeCost/unsetEmployeeCost`، **hr.cost**):** تُخزَّن في `Setting` (مفتاح `hr.cost:{userId}`, `branchId=null`, JSON) — **لا تغيير schema**. SUPER_ADMIN فقط.
- **تفصيل التكاليف (`costs`):** مصروفات حسب الفرع×الفئة + رواتب (تُوزَّع بالتساوي على فروع الموظف المشترك). دلو `__shared__` للمصروفات بـ branchId=NULL (مُقيَّد بـ orgId لمنع تسريب بين المستأجرين).

### 9.3 عرض الاشتراك للمنشأة (`OrgSubscriptionController`)
- **`GET /org/entitlements`:** لقطة استحقاقات المنشأة (active/readOnly/status/trial/expired/suspended/planName/currentPeriodEnd/features/maxBranches/maxUsers/usedBranches/usedUsers). أي موظف قد يقرأها (تغذّي إخفاء الخصائص المعطَّلة + لافتة الحالة). التفاصيل في `EntitlementResolver` (ملف 01).
- **`GET /org/subscription`:** مُحرَّس `requireManager`. الخطة + التجديد + سجل رسوم `OnlineCharge purpose=SUBSCRIPTION` (آخر 50) + كتالوج الخطط النشطة (الحالية معلَّمة). حالة مشتقّة `EXPIRED` لفترة منتهية.

---

## 10. الدعم والإعلانات

### 10.1 الدعم — جانب المنشأة (`OrgSupportController`, `/org/support`)
- مُقيَّد صارماً على منشأة المتصل. index (تذاكر المنشأة + فئات الدعم المُعدّة)، show، store (فتح تذكرة برسالة أولى؛ فئة تُبدأ بها كبادئة في الموضوع لأن `SupportTicket` بلا عمود category؛ رد آلي اختياري)، reply (رد المنشأة يُعيد فتح تذكرة محلولة).
- الأولويات: `LOW/NORMAL/HIGH/URGENT`. authorType على الرسائل: `TENANT`/`ADMIN`.

### 10.2 الدعم — جانب الأدمن (`AdminSupportController`, `/admin/support`)
- القراءة لأي أدمن؛ الكتابة **`manage_support`**. index (صندوق التذاكر + فلترة حالة + عدّادات + كشف SLA — `slaResponseMinutes` قابل للضبط، و`awaitingUs` من نوع آخر رسالة)، show، reply (يرفع lastReplyAt وينقل الحالة إلى `PENDING`)، update (status/priority). الحالات: `OPEN/PENDING/RESOLVED/CLOSED`.

### 10.3 الإعلانات / الإشعارات (Notices)
- **الكتابة (الأدمن، `AdminAnnouncementController`, `manage_announcements`):** CRUD للافتات؛ `orgId=null` بثّ للكل، قيمة محدّدة لمنشأة واحدة؛ المستوى `INFO/WARNING/CRITICAL` (CRITICAL = تنبيه صيانة). افتراضيات المستوى/نافذة الانتهاء من `PlatformSettings.announcements`. (نظير في لوحة المالك: `PlatformExtraController`.)
- **القراءة (المنشأة، `OrgNoticeController`, `/org/notices`):** الإعلانات النشطة داخل النافذة (`startsAt/endsAt`) المستهدِفة لكل المستأجرين أو لهذه المنشأة، محدودة بـ `tenantNoticeLimit` (افتراضي 10)، تُعرَض كلافتة في لوحة المنشأة.

---

## 11. قواعد البيزنس (مرقّمة)

1. **صف اشتراك واحد لكل منشأة** (`PlatformSubscription` واحد per orgId؛ `updateOrCreate` على orgId).
2. **منشأة بلا صف اشتراك = grandfathered:** نشطة بالكامل، كل الخصائص، حدود لانهائية — إلا إن عُلِّقت. الإنفاذ يبدأ فقط عند وضعها على خطة/تجربة.
3. **حالة `EXPIRED` مشتقّة لا مُخزَّنة:** `ACTIVE/TRIAL` بعد مضي `currentPeriodEnd`.
4. **`isSuspended` مستقل عن الاشتراك** — إيقاف صارم يجعل الحساب للقراءة فقط، ويُخرِج المنشأة من Dunning.
5. **إضافة فرع/مستخدم** تتطلّب اشتراكاً نشطاً + عدم تجاوز الحد (`assertBranchQuota`/`assertUserQuota`). المستخدمون النشطون فقط يستهلكون مقاعد.
6. **لا تعطيل آخر فرع نشط** ولا **آخر SUPER_ADMIN نشط** في المنشأة.
7. **منع تصعيد الأدوار داخل المنشأة** (`assertRoleCeiling`) وعلى مستوى المنصة (منح OWNER/`manage_admins` وصكّ صلاحية لا تملكها — كلها OWNER فقط).
8. **soft delete دائماً** للمستخدمين والمنشآت (`isActive=false` / `archivedAt`)، لا حذف صلب لبيانات مشتركة.
9. **فوترة الاشتراك/الأجهزة على خطوتين:** DRAFT (بلا سلسلة/بلا قيد) → ISSUED (سلسلة ICV/PIH + قيد إيراد، ذرّياً). الاعتماد ذرّي (409 عند التزامن)، لا يمكن حذف معتمَدة.
10. **سلسلتان منفصلتان:** `SUB-` للاشتراكات و`DEV-` للأجهزة، كل منهما تتقدّم فوق ISSUED فقط.
11. **السعر شامل للضريبة** دائماً (VAT مستخرَجة 15/115) في فواتير الاشتراك والأجهزة.
12. **Dunning: لا سحب تلقائي**؛ كل مرحلة (منشأة×مرحلة×فترة) مرة واحدة فقط (مُتتبَّعة في AuditLog)؛ التعليق بعد `graceDays`.
13. **الكوبونات** تُستهلَك ذرّياً ولا تتجاوز `maxRedemptions`؛ الأنواع `PERCENT/FIXED/FREE_MONTHS`.
14. **سقف ملكية الشركاء** (افتراضي 100%) على النشطين، مُنفَّذ بقفل advisory؛ التوزيع/السداد ذرّيان مع قيد محاسبي مطابق.
15. **دفاتر المنصة org محجوزة** تُستثنى من كل قائمة/عدّ مواجه للمستأجرين (`tenantsOnly`/`isPlatformOrg`) ولا يمكن إصدار فاتورة أجهزة لها.
16. **التسجيل الذاتي يُحترَم قفله** (`allowPublicSignup`) قبل أي validation.
17. **الانتحال لا يمنح صلاحيات منصة أبداً** (`isPlatformOwner=false`)، ويتخطّى مستخدمي المنصة، ويُبطَل من الخادم.
18. **حارس `/admin/*` يعيد التحقّق من الـ DB الحيّ** في كل طلب (تعطيل/تخفيض يسري فوراً).
19. **تصدير بيانات المنشأة يُفصِح صراحةً عن البتر** عند تجاوز سقف العملاء.
20. **تكاليف الموظفين وتجاوزات الصلاحيات والأدوار المخصّصة** تُخزَّن في `Setting`/`PlatformConfig` (JSON) لا في schema جديد.

---

## 12. الأدوار والصلاحيات الدقيقة + قائمة العمليات الكاملة

### 12.1 أدوار المنصة (`PlatformAccess::ROLES`)
`OWNER` (مالك المنصّة) · `SUPPORT` (دعم) · `SALES` (مبيعات) · `FINANCE` (مالية) · `VIEWER` (مشاهدة فقط). OWNER يملك كل صلاحية ضمنياً (يتجاوز الفحوص).

### 12.2 مفاتيح الصلاحيات الدقيقة (`PlatformAccess::PERMISSIONS`)
`manage_tenants` · `manage_subscriptions` · `manage_plans` · `manage_admins` · `manage_crm` · `manage_leads` · `manage_accounting` · `manage_support` · `manage_marketing` · `manage_announcements` · `manage_config` · `view_finance` · `manage_partners` · `manage_whatsapp` · `manage_payouts`.

### 12.3 presets الأدوار (`ROLE_PRESETS`)
- **OWNER:** كل الصلاحيات (ضمنياً).
- **SUPPORT:** `manage_tenants, manage_crm, manage_support`.
- **SALES:** `manage_crm, manage_leads, manage_marketing, view_finance`.
- **FINANCE:** `view_finance, manage_subscriptions, manage_accounting, manage_payouts`.
- **VIEWER:** لا شيء.

### 12.4 الأدوار المخصّصة (`AdminRoleController`, `platform.customRoles`)
presets صلاحيات مُسمّاة قابلة لإعادة الاستخدام (لا schema)، مُحرَّسة `manage_admins`. CRUD (index/store/update/destroy)، مع رفض حذف مفتاح غير موجود (404 بدل تدقيق كاذب).

### 12.5 قائمة العمليات الكاملة والصلاحية الدقيقة لكل عملية

**التسجيل (عام):**
| العملية | المسار | الحارس |
|---|---|---|
| التسجيل الذاتي + التزويد | `POST /signup` | عام + `throttle:signup` + `allowPublicSignup` |

**إدارة المنشأة الذاتية (staff):**
| العملية | المسار | الحارس |
|---|---|---|
| قائمة/كتالوج المستخدمين | `GET /org/users`, `/org/branches`, `/org/roles` | manager (SUPER_ADMIN/BRANCH_MANAGER) |
| إنشاء/تعديل/تعطيل مستخدم | `POST/PATCH/DELETE /org/users[/{id}]` | manager + roleCeiling + last-super-admin + quota/active |
| CRUD الفروع | `GET/POST/PATCH /organization/branches[/{id}]` | manager (+quota/active للإضافة) |
| أداء الفروع/الموظفين | `GET /organization/performance/*` | manager |
| ضبط/إلغاء تكلفة موظف | `PUT/DELETE /organization/employees/{userId}/cost` | **SUPER_ADMIN فقط** |
| تفصيل التكاليف | `GET /organization/costs` | manager |
| استحقاقات المنشأة | `GET /org/entitlements` | أي staff |
| عرض الاشتراك | `GET /org/subscription` | manager |
| notices/الدعم (منشأة) | `GET /org/notices`, `/org/support*` | staff (org-scoped) |

**لوحة المالك (`/platform/*` — كلها `assertPlatformOwnerRole` = OWNER حيّ):**
stats · tenants(index/show) · plans(index/store/update) · users · affiliates · leads · announcements(CRUD) · activity · tenants/{orgId}/users · impersonate(start/stop) · tenants/{orgId}/subscription(get/put) · tenants/{orgId}/addons(get/toggle) · delivery-methods/drivers.

**كونسول الأدمن (`/admin/*` — `assertSystemAdmin` + صلاحية دقيقة):**
| المجال | القراءة | الكتابة |
|---|---|---|
| المنشآت (index/show/features) | `manage_tenants` (features: أي أدمن) | `manage_tenants` |
| entitlements/suspend/profile/archive/unarchive | — | `manage_tenants` |
| export بيانات المنشأة | — | **OWNER فقط** |
| الاشتراك (updateSubscription/startTrial) | — | `manage_subscriptions` |
| extend الفترة | — | `manage_subscriptions` |
| applyCredit | — | `manage_accounting` |
| notes/meta/message (control-center) | — | `manage_tenants` |
| impersonate (admin) | — | **OWNER فقط** |
| الخطط | `manage_plans` | `manage_plans` |
| الكوبونات (+validate) | `manage_subscriptions` | `manage_subscriptions` |
| فواتير الاشتراك (index/show) | `view_finance` | store: `manage_subscriptions` · confirm: `manage_accounting` · destroy: `manage_subscriptions` |
| الأجهزة (كتالوج) | أي أدمن | `manage_subscriptions` |
| مبيعات الأجهزة | `view_finance` | store: `manage_subscriptions` · confirm: `manage_accounting` · destroy: `manage_subscriptions` |
| الشركاء (كل شيء) | `manage_partners` | `manage_partners` (options: `manage_accounting`) |
| Dunning | `manage_subscriptions` | `manage_subscriptions` |
| العملاء المحتملون (leads) | `manage_leads` | `manage_leads` |
| أدمن المستخدمون (users/permissions) | `manage_admins` | `manage_admins` (+OWNER لمنح OWNER/manage_admins) |
| الأدوار المخصّصة | `manage_admins` | `manage_admins` |
| الإعدادات (config) | **OWNER فقط** | **OWNER فقط** |
| المستندات | `manage_tenants` | `manage_tenants` (serve: رابط موقّع) |
| الدعم | أي أدمن (index/show: `manage_support`) | `manage_support` |
| الإعلانات | `manage_announcements` | `manage_announcements` |
| المحاسبة (دفاتر المنصة) | (ملف 08 — reads open) | `manage_accounting` |

---

## 13. حالات خاصة وفجوات

1. **ازدواج منطق التزويد:** `SignupController::store` يكرّر منطق `TenantProvisioner::provision` يدوياً بكتالوج **مختلف** (4 فئات + أحذية بدل 3 فئات). أي تعديل مستقبلي على التزويد يجب أن يُطبَّق في المكانين، وإلا تتباين المنشآت المُنشأة عبر التسجيل الذاتي عن تلك المُنشأة من الكونسول/التحويل.
2. **`Rule::unique('User','email')` عالمي:** البريد فريد عبر **كل** المنصة (كل المستأجرين + الأدمن)، لا داخل المنشأة فقط. لا يمكن لشخصين في منشأتين مختلفتين استخدام نفس البريد.
3. **الحالة المشتقّة `EXPIRED`** لا تُخزَّن؛ لذا الاستعلامات المباشرة على `status` لن تراها — تحتاج مقارنة `currentPeriodEnd`. Dunning هو ما يحوّل الحالة فعلياً إلى `PAST_DUE` عند الاستحقاق (وليس تلقائياً بمرور الوقت).
4. **قيد enum `JournalSource`/`PaymentMethod`:** لا `SUBSCRIPTION`؛ التدفّقات الجديدة تُميَّز بـ `refType` (`SubscriptionInvoice`/`DeviceSale`/`PlatformPartnerDistribution`).
5. **Dunning يحمي دورته من الأعطال:** كتابة AuditLog تُنفَّذ بـ `forceCreate` (لا `create`) لأن الجدول بلا `$fillable`؛ استخدام `create` كان يُجهِض الدورة كلها عند أول منشأة مستحقّة تاركاً البقية بلا تذكير.
6. **تصدير بيانات المنشأة مبتور بسقف 10آلاف عميل** مع إفصاح صريح (`customersTruncated/exportNote`) — ليس تصديراً كاملاً للبيانات فوق السقف.
7. **الانتحال يتخطّى مستخدمي المنصة** عمداً: انتحال مستخدم `isPlatformOwner` كان سيعيد توكناً يختمه الـ middleware كـ isPlatformOwner فيصير session منصة كامل.
8. **`applyCredit` يضيف رصيداً دائناً فقط في `accountCredit`** لكن **لا يوجد في الشيفرة المقروءة منطق صرف تلقائي لهذا الرصيد** على فاتورة الاشتراك التالية — التطبيق الفعلي للخصم على الفاتورة غير ظاهر هنا (يُعتمَد عليه يدوياً عبر `customPrice` عند إصدار الفاتورة).
9. **لوحتان متوازيتان لنفس الوظائف** (`/platform/*` القديمة و`/admin/*` الحديثة): مثلاً ضبط الاشتراك، الإعلانات، مستخدمو المنشأة، الانتحال — موجودة في كليهما بحارسين مختلفين. `/platform/*` أضعف حبكاً (لا صلاحيات دقيقة، claims فقط في بعض المسارات) لكنها محصورة بـ OWNER.
10. **`OrgAddOn` النشط يُحسَب فقط إن كان الاشتراك `active`** (في `EntitlementResolver`): منشأة مُنتهية/مُعلَّقة تفقد إضافاتها المدفوعة وتبقى على core فقط.
11. **`payoutConfig`/`accountCredit`/التسويات** تتقاطع مع ملف 05 (التسويات البنكية) — التفاصيل المالية للتحويل هناك.
12. **`DunningService` ينشئ فاتورة تجديد مسوّدة (DRAFT) فقط** عند الاستحقاق — لا يعتمدها (لا يُرحّل إيراداً)؛ الاعتماد يبقى إجراءً محاسبياً يدوياً بعد استلام الدفع.
13. **حماية آخر OWNER** تشمل الصفوف القديمة ذات `platformRole=null` (تُعامَل OWNER)، ومُسلسَلة بـ `lockForUpdate` لمنع سباق TOCTOU يقفل كل المُلّاك.
