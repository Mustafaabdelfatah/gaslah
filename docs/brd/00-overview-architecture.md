# BRD 00 — نظرة عامة، المعمارية، والأنماط المشتركة

> هذا هو ملف الجذر لحزمة الـ BRD الخاصة بمنصة **غسلة (Gaslah)**. اقرأه أولاً — فهو يشرح المفاهيم والأنماط التي تتكرر في كل الموديولات، بحيث لا تُعاد في كل ملف. باقي الملفات تفترض معرفة هذا الملف.
>
> **الهدف من الحزمة:** مواصفة أعمال (BRD) كاملة تسمح بإعادة بناء **نفس البيزنس بالظبط** بكود نضيف وبنية جديدة — بدون الرجوع للكود القديم. المصدر: فرع الإنتاج `release/production-ready` (البرانش الحيّ). تاريخ الاستخراج: 2026-08-26.
>
> **نطاق التوثيق:** البيزنس (المنطق، التدفقات، القواعد، حقول الكيانات، الأدوار، حركة الفلوس). **لا** يشمل أشكال الـ request/response أو قواعد الـ validation التقنية (min/max) — الشات الذي يعيد البناء يصمّم طبقة الـ API بنفسه بناءً على البيزنس الموصوف.

---

## 1. ما هو المنتج

**غسلة** منصة SaaS تبيع لأصحاب المغاسل نظام إدارة تشغيلي ومالي كامل. كل مغسلة = **منشأة (Organization / Tenant)** لها فرع أو أكثر، وتشترك في المنصة بخطة شهرية/سنوية. مالك المنصة (غسلة) يتابع كل المنشآت ويحصّل اشتراكاتها ويديرها.

### الأربعة عوالم (كل عالم مصادقة وصلاحيات مستقلة)
| العالم | المستخدم | البوابة | نوع التوكن |
|---|---|---|---|
| **المنشأة (Staff)** | موظفو المغسلة | التطبيق الرئيسي | `staff` |
| **العميل (Portal)** | عميل المغسلة | بوابة العميل | `customer` |
| **المنصة (Platform)** | مالك/موظفو غسلة | لوحتا `/platform` و`/admin` | `platform` |
| **أطراف خارجية** | سائق / مورّد سوق / مسوّق | بوابات مستقلة | `driver` / `supplier` / `affiliate` |

---

## 2. نموذج تعدد المنشآت (Multi-Tenancy)

```
Organization (منشأة)
 ├── Branch (فرع)  — الفرع الرئيسي دائماً code=MAIN
 │    ├── UserBranch — يربط User بالفرع + role في هذا الفرع
 │    ├── Customer
 │    ├── Order, Payment, Shift, DeliveryRequest, ...
 │    └── ...
 └── PlatformSubscription — اشتراك المنشأة في المنصة (صف واحد لكل منشأة)
```

- **User عالمي:** الـ email فريد عبر كل المنشآت. لا ينتمي مباشرة لمنشأة — يُشتق `orgId` من أول فرع مرتبط به. `User.role` = أعلى دور له في فروعه.
- **العزل:** كل استعلام مقيّد بـ `orgId` (ومجموعة `branchIds`) المشتقّة من التوكن. لا يمكن لمنشأة رؤية بيانات منشأة أخرى إطلاقاً.
- **مبدّل الفروع:** رأس `X-Branch-Id` يضيّق نطاق القراءة لفرع واحد داخل المنشأة — **مرشّح قراءة فقط، لا يوسّع النطاق ولا يغيّر فرع الكتابة**.
- تفاصيل الأدوار والصلاحيات والاستحقاقات: انظر [01-auth-roles-tenancy](01-auth-roles-tenancy.md).

---

## 3. نموذج البيانات (اصطلاحات عامة)

> **ملاحظة لإعادة البناء:** الاصطلاحات أدناه موروثة من قاعدة بيانات Prisma المشتركة المجمّدة. **في البناء النضيف أنت حر في اختيار اصطلاحاتك** (snake_case، أعمدة، إلخ) — المهم هو **الحقول ومعانيها وعلاقاتها**، وهي موصوفة في كل ملف موديول.

- الجداول PascalCase مفردة (`Order`, `OrderItem`)، الأعمدة camelCase (`orderNo`, `branchId`).
- المفاتيح الأساسية **cuid نصية** (`c` + طابع زمني + عشوائي)، ليست أرقاماً متزايدة.
- الطوابع: معظم الجداول `createdAt`/`updatedAt`؛ بعضها `createdAt` فقط، وبعضها طوابع مخصّصة (`openedAt`, `deliveredAt`).
- **enums هي Postgres enums** في النظام القديم (قيم ثابتة). في البناء النضيف اجعلها enums حقيقية في الكود/DB.
- **جدول `Setting` (key-value):** في النظام القديم يُستخدم لتخزين كل كيان/إعداد جديد كـ JSON (بسبب تجميد الـ schema) بمفتاح `{type}:{orgId}:{id}`. **في البناء النضيف: حوّل كل هذه إلى جداول حقيقية.** الملفات تشير إلى ما هو مخزّن حالياً في `Setting` حتى تعرف أنه بيانات تحتاج جدولاً.

### قائمة الكيانات الرئيسية (والملف الذي يفصّلها)
| المجال | الكيانات | الملف |
|---|---|---|
| المصادقة | User, UserBranch | [01](01-auth-roles-tenancy.md) |
| الكتالوج | ServiceCategory, Product, Service, GarmentType, Unit | [02](02-catalog-customers.md) |
| العملاء | Customer, CustomerAddress | [02](02-catalog-customers.md) |
| الطلبات | Order, OrderItem, OrderStatusHistory | [03](03-orders-pos.md) |
| التوصيل | DeliveryRequest, DeliveryZone, Driver, DeliveryStatusHistory | [04](04-delivery.md) |
| المدفوعات | Payment, WalletTransaction, OnlineCharge, PayoutSettlement, SettlementApproval | [05](05-payments-wallet-payouts.md) |
| الاشتراكات/الولاء | Subscription, SubscriptionPlan, LoyaltyAccount, LoyaltyProgram, LoyaltyTransaction | [06](06-subscriptions-loyalty.md) |
| المحاسبة | Account, JournalEntry, JournalLine, Expense | [08](08-accounting-assets-payables.md) |
| المخزون | InventoryItem, Supplier, PurchaseOrder, PurchaseOrderItem, Shift | [09](09-reports-analytics-operations.md) |
| المراسلة | WaMessage, WaTemplate, OtpCode, Notification, Conversation, Message, SupportTicket, SupportMessage, CrmNote, Lead | [10](10-messaging-support-content.md) |
| المحتوى | BlogPost, BlogCategory, ForumCategory, ForumThread, ForumPost, SocialPost, OrgAnnouncement | [10](10-messaging-support-content.md) |
| الأفيليت | Affiliate, AffiliateReferral | [10](10-messaging-support-content.md) |
| ZATCA | ZatcaInvoice | [11](11-zatca.md) |
| المنصة | Organization, Branch, PlatformPlan, PlatformSubscription, SubscriptionInvoice, PlatformConfig, PlatformCoupon, PlatformDevice, DeviceSale, PlatformPartner, PlatformPartnerDistribution, PlatformExpense, PlatformEvent, PlatformAnnouncement, OrgAddOn, OrgDocument | [12](12-platform-tenants.md) |
| السوق | MarketProduct, MarketOrder, MarketOrderItem, MarketSupplier | [13](13-market-settings-audit.md) |
| الإعدادات | Setting, AuditLog | [13](13-market-settings-audit.md) |

---

## 4. الأنماط المشتركة (Cross-Cutting) — تنطبق على كل الموديولات

هذه الأنماط هي **جوهر قيمة النظام** — يجب الحفاظ عليها حرفياً في البناء النضيف. الملفات تشير إليها بالاسم بدل إعادة شرحها.

### 4.1 نزاهة الفلوس (Money Integrity)
1. **كل حركة محفظة عميل تمر عبر خدمة محفظة واحدة** تأخذ `SELECT ... FOR UPDATE` على صف العميل داخل المعاملة. **ممنوع** قراءة الرصيد وكتابته يدوياً (lost update / double-spend).
2. **الدفع بحضور العميل** (خصم محفظة/اشتراك) **يتطلب موافقة العميل بـ OTP** + **حرق توكن الإثبات ذرّياً قبل تحرّك أي مال** (advisory lock check-and-set). تفاصيل: [03](03-orders-pos.md) §OTP.
3. **كل ترحيل محاسبي idempotent** على `(orgId, source, refType, refId)`. القيود الدورية (كالإهلاك) تخصّص `refId` بالفترة (`assetId:YYYY-MM`) وإلا يُرحَّل أول مرة فقط.
4. **أعد حساب كل الأسعار خادمياً** من الكتالوج — لا تثق بسعر قادم من العميل أبداً.
5. **الآثار الجانبية best-effort:** حركة المال أولاً داخل المعاملة، ثم المحاسبة والإشعارات بعد الـ commit بحيث لا يكسر فشلها العملية المالية.

### 4.2 الأمان (Security)
1. **إعادة تحقق حيّة من قاعدة البيانات في كل طلب** — التوكن لقطة قديمة؛ حساب معطّل/محذوف/تغيّر دوره يُرفض/يُحدَّث فوراً.
2. **فصل أنواع التوكنات** ومنع تصعيد الصلاحيات (توكن عميل لا يُصادِق كموظف).
3. **fail-closed** على تدفقات OTP والويبهوكات (بدون مزوّد/سرّ حقيقي، ارفض بدل التساهل).
4. **إخفاء الأسرار وأرقام الهواتف و OTP في السجلات**، وعدم إرسال الأسرار للمتصفح أبداً.

### 4.3 التزامن (Concurrency)
- **أقفال الصفوف** (`lockForUpdate`) على الأرصدة (محفظة، ولاء، اشتراك) والدفعات.
- **أقفال استشارية** (`pg_advisory_xact_lock`) لتسلسل العمليات الحسّاسة (حرق التوكن، حصص الرسائل، إنشاء دفعة تسوية، سلسلة ICV، أرقام القيود).
- **فهارس فريدة جزئية** لفرض الثوابت (وردية مفتوحة واحدة لكل كاشير، دفعة تسوية مفتوحة واحدة لكل منشأة، ICV فريد لكل منشأة).
- **Idempotency keys** (`clientRequestId` للطلبات، `gateway:{txnId}` للمدفوعات) لمنع الازدواج.

### 4.4 الأتمتة الخلفية (Background)
مهام دورية تعمل بلا تدخل: كنس الأتمتة (تقديم حالات الطلبات، كل 5 دقائق)، طابور رسائل واتساب، الـ dunning، تسوية المدفوعات، الاشتراكات المتكررة، إهلاك الأصول، النسخ الاحتياطي. التفاصيل في ملفات الموديولات المعنية.

---

## 5. فهرس ملفات الـ BRD

| # | الملف | المحتوى |
|---|---|---|
| 00 | overview-architecture | هذا الملف — المفاهيم والأنماط المشتركة |
| 01 | [auth-roles-tenancy](01-auth-roles-tenancy.md) | المصادقة، التوكنات، الأدوار، الصلاحيات، الاستحقاقات، تسجيل الدخول |
| 02 | [catalog-customers](02-catalog-customers.md) | الكتالوج، العملاء، العناوين، الآجل/الائتمان |
| 03 | [orders-pos](03-orders-pos.md) | الطلبات، نقطة البيع، OTP، دورة الحياة، الأتمتة، الإلغاء |
| 04 | [delivery](04-delivery.md) | التوصيل، السائقون، المناطق، شاشة العرض |
| 05 | [payments-wallet-payouts](05-payments-wallet-payouts.md) | المدفوعات، المحفظة، البوابات، التسويات |
| 06 | [subscriptions-loyalty](06-subscriptions-loyalty.md) | اشتراكات العملاء، الولاء |
| 07 | [portal](07-portal.md) | بوابة العميل |
| 08 | [accounting-assets-payables](08-accounting-assets-payables.md) | المحاسبة، الأصول، الميزانيات، المصروفات، الذمم الدائنة |
| 09 | [reports-analytics-operations](09-reports-analytics-operations.md) | التقارير، التحليلات، لوحة المعلومات، المخزون، الموردون، الورديات، البنوك |
| 10 | [messaging-support-content](10-messaging-support-content.md) | واتساب، الإشعارات، الدعم، CRM، المجتمع، المحتوى، الأفيليت، الأتمتة |
| 11 | [zatca](11-zatca.md) | الفوترة الإلكترونية ZATCA (المرحلتان + onboarding) |
| 12 | [platform-tenants](12-platform-tenants.md) | إدارة المنصة، المنشآت، اشتراكات المنصة، الفوترة، dunning، الأجهزة، الشركاء |
| 13 | [market-settings-audit](13-market-settings-audit.md) | السوق B2B، الإعدادات، التدقيق، التسجيل/التزويد |

---

## 6. قواعد ذهبية لإعادة البناء (ملخّص — التفاصيل في §4)

**احتفظ بها:** نزاهة الفلوس (§4.1)، الأمان (§4.2)، التزامن (§4.3)، الأنماط المحاسبية (idempotency + القيد المزدوج).

**تخلّص منها (ديون تقنية):** تخزين الكيانات كـ JSON في `Setting`، الـ cache الملفّي للحالة، `env()` داخل الكود بعد `config:cache`، الحالة الدائمة على القرص المحلي، غياب pagination، تجميع التقارير في PHP بدل SQL، `QUEUE=sync`، غياب طبقة الخدمات، غياب API versioning.

**أصلح فجوات معروفة:** تجديد/إلغاء اشتراكات العملاء، كسب نقاط الولاء التلقائي، حركة المخزون التلقائية، إنشاء/استلام أوامر الشراء، ربط دفعة Moyasar بالطلب إلزامياً، XAdES/C14N في ZATCA. (كل فجوة موصوفة في ملف موديولها.)
