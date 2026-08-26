# مخطّط الجداول — 09 المراسلة والدعم والمحتوى

> مصدر التصميم: `../10-messaging-support-content.md`. هذا مخطّط الهدف (target) بعد التحويل من نماذج Prisma الحالية (PascalCase, cuid) إلى اصطلاح snake_case/bigint.
> **الاستيراد**: كل جدول مُهاجَر من نموذج Prisma قائم يحمل `legacy_cuid` (نص، nullable، unique) لربط الصف بمُعرّفه القديم أثناء الترحيل، ثم يُهمَل بعد اكتمال الهجرة.
> **الأنواع/الحالات**: كلها `varchar` + PHP enum (backed) + قيد `CHECK` على مجموعة القيم — لا نستخدم Postgres enum (لتفادي هشاشة تعديل الـ enum لاحقاً).
> **النطاق**: جداول المنشأة تحمل `organization_id`؛ جداول عالمية للمنصة (المنتدى، المدوّنة، منشورات السوشال، مراسلة المشرفين) بلا `organization_id` أو بحقل إسناد nullable — منوَّه أدناه لكل جدول.
> **ملاحظة تنسيق (Leads)**: `leads` و`crm_notes` تخصّان خطّ مبيعات المنصة وليست مغطّاة في ملف مخطّط المنصة (`12-platform-tenants` بلا مخطّط بعد)؛ صُمّمت هنا لأنها الموطن المنطقي (قسم CRM §7). عند إنشاء مخطّط المنصة يجب **عدم تكرارها** والإحالة لهذا الملف.

---

## فهرس الجداول

| # | الجدول | كان | النطاق |
|---|--------|-----|--------|
| 1 | `wa_messages` | `WaMessage` | منشأة (org قد يكون null لرسائل المنصة) |
| 2 | `wa_templates` | `WaTemplate` | منشأة + افتراضي منصة (org=null) |
| 3 | `otp_codes` | `OtpCode` | مشترك (org قد يكون null) |
| 4 | `notifications` | `Notification` | منشأة (عبر العميل) |
| 5 | `conversations` | `Conversation` | منصة (مراسلة مشرفين) |
| 6 | `conversation_participants` | `ConversationParticipant` | منصة |
| 7 | `messages` | `Message` | منصة (حذف ناعم) |
| 8 | `support_tickets` | `SupportTicket` | منشأة |
| 9 | `support_messages` | `SupportMessage` | منشأة (عبر التذكرة) |
| 10 | `crm_notes` | `CrmNote` | منصة (CRM) |
| 11 | `leads` | `Lead` | منصة (مبيعات) |
| 12 | `blog_categories` | `BlogCategory` | عالمي |
| 13 | `blog_posts` | `BlogPost` | عالمي |
| 14 | `forum_categories` | `ForumCategory` | عالمي |
| 15 | `forum_threads` | `ForumThread` | عالمي (org إسناد nullable) |
| 16 | `forum_posts` | `ForumPost` | عالمي |
| 17 | `social_posts` | `SocialPost` | منصة (تسويق المنصة) |
| 18 | `org_announcements` | `OrgAnnouncement` | منشأة |
| — | `wa_org_limits`, `wa_branch_limits`, `messaging_configs` | Setting-JSON | تحويلات §تحويلات |

---

## 1. المراسلة (wa_messages, wa_templates)

### `wa_messages`  ← كان: `WaMessage`
> سجل محاولة إرسال كل رسالة واتساب/SMS — **مصدر الحقيقة للحصص والإحصائيات**. الصف يُنشأ بحالة `QUEUED` (تُحتسب فوراً في الحصة) أو `BLOCKED` (حجب تجاري، لا تُحتسب)، ثم يتقدّم عبر إيصالات الويبهوك.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique، مُعرّف Prisma القديم |
| `organization_id` | bigint | نعم | — | FK→`organizations.id` ON DELETE CASCADE. **null = رسالة مستوى منصة** (OTP سائق/مسوّق) تتجاوز حصص org |
| `branch_id` | bigint | نعم | — | FK→`branches.id` ON DELETE SET NULL |
| `customer_id` | bigint | نعم | — | FK→`customers.id` ON DELETE SET NULL |
| `order_id` | bigint | نعم | — | FK→`orders.id` ON DELETE SET NULL |
| `to_phone` | varchar(32) | لا | — | الرقم المطبّع (E.164) |
| `channel` | varchar(16) | لا | `'WHATSAPP'` | CHECK ∈ (WHATSAPP, SMS) |
| `category` | varchar(16) | لا | — | CHECK ∈ (MARKETING, UTILITY, AUTHENTICATION, SERVICE) |
| `event_key` | varchar(32) | نعم | — | orderCreated/orderReady/orderCompleted/otp/invoice/delivery/manual/test |
| `template_id` | bigint | نعم | — | FK→`wa_templates.id` ON DELETE SET NULL |
| `body` | text | لا | — | النص المُصيَّر (يُخفى في العرض إن AUTHENTICATION/otp) |
| `sender_mode` | varchar(16) | نعم | — | CHECK ∈ (PLATFORM, CUSTOM). يُملأ وقت الإرسال |
| `status` | varchar(16) | لا | `'QUEUED'` | CHECK ∈ (QUEUED, SENT, DELIVERED, READ, FAILED, BLOCKED) |
| `provider_message_id` | varchar(128) | نعم | — | مُعرّف رسالة المزوّد (مفتاح مطابقة إيصالات الويبهوك) |
| `error` | text | نعم | — | سبب الحجب/الفشل بالعربي |
| `sent_at` | timestamptz | نعم | — | — |
| `delivered_at` | timestamptz | نعم | — | — |
| `read_at` | timestamptz | نعم | — | — |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:**
  - `idx_wa_messages_quota_org` على `(organization_id, status, created_at)` — **الفهرس الحرج لعدّ الحصة الشهرية**: `monthUsed(orgId)` يعدّ صفوف `status ∈ COUNTED_STATUSES(QUEUED,SENT,DELIVERED,READ)` منذ بداية الشهر؛ الفهرس المركّب يغطّي الفلترة (org + status + نطاق التاريخ).
  - `idx_wa_messages_quota_branch` على `(organization_id, branch_id, status, created_at)` — عدّ حصة الفرع.
  - `idx_wa_messages_provider_msg` على `(provider_message_id)` WHERE `provider_message_id IS NOT NULL` — مطابقة إيصالات الويبهوك.
  - `idx_wa_messages_org_created` على `(organization_id, created_at DESC)` — شاشة `/wa/messages` (الأحدث أولاً + فلاتر status/category).
  - `idx_wa_messages_customer` على `(customer_id)`، `idx_wa_messages_order` على `(order_id)`.
  - **قيد ذرّية الحصة** (على مستوى التطبيق لا DB): الإدراج + عدّ الحصة يجريان داخل `pg_advisory_xact_lock(hashtext('wa-quota:'||organization_id))` لكل منشأة — يُسلسِل فحص-الحصة + الإدراج فلا يتجاوز إرسالان متزامنان الحد. `BLOCKED` **خارج** COUNTED_STATUSES فلا يستهلك حصة.
- **علاقات:** ينتمي لـ `organizations`, `branches`, `customers`, `orders`, `wa_templates`.

### `wa_templates`  ← كان: `WaTemplate`
> قوالب رسائل واتساب. `organization_id=null` = قالب افتراضي على مستوى المنصة (للقراءة فقط من المنشأة). أولوية الحل: قالب org نشط ← قالب منصة افتراضي ← نص legacy ← احتياطي مدمج.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `organization_id` | bigint | نعم | — | FK→`organizations.id` ON DELETE CASCADE. **null = افتراضي منصة** |
| `name` | varchar(120) | لا | — | اسم القالب |
| `category` | varchar(16) | لا | — | CHECK ∈ (MARKETING, UTILITY, AUTHENTICATION, SERVICE) |
| `event_key` | varchar(32) | نعم | — | null = قالب إرسال يدوي (manual) |
| `body` | text | لا | — | نص بمتغيّرات `{var}` |
| `is_active` | boolean | لا | true | — |
| `created_by_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_wa_templates_resolve` على `(organization_id, event_key, is_active, updated_at DESC)` — حل القالب بالأولوية (org أولاً ثم null، الأحدث نشط).
- **علاقات:** ينتمي لـ `organizations`, `users` (المُنشئ)؛ يملك `wa_messages`.

---

## 2. رموز التحقق (otp_codes)

### `otp_codes`  ← كان: `OtpCode`
> جدول **مشترك** بين كل تدفّقات الرموز (بوابة العميل، محفظة نقاط البيع، سائق، مسوّق، دفع طلب). التقسيم بـ `purpose` **حاجز أمني**: كل تدفّق يقرأ فقط رموز غرضه — منعاً لمقايضة رمز موافقة محفظة برمز دخول بوابة. `code_hash` مخفي دائماً عن التسلسل. بلا `updated_at` (أصلاً `timestamps=false`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `organization_id` | bigint | نعم | — | FK→`organizations.id` ON DELETE CASCADE. **null = فضاء منصة منفصل** (AFFILIATE_LOGIN، DRIVER_LOGIN بمفتاح سائق) |
| `phone` | varchar(32) | لا | — | الرقم المطبّع |
| `code_hash` | varchar(255) | لا | — | bcrypt، **hidden** (لا يُسلسَل أبداً) |
| `purpose` | varchar(24) | لا | — | CHECK ∈ (PORTAL_LOGIN, POS_WALLET, DRIVER_LOGIN, AFFILIATE_LOGIN, ORDER_PAYMENT) |
| `expires_at` | timestamptz | لا | — | صلاحية 5 دقائق |
| `consumed_at` | timestamptz | نعم | — | الاستهلاك الوحيد الذرّي (`UPDATE … WHERE consumed_at IS NULL`) |
| `attempts` | smallint | لا | 0 | حدّ 5 (منع القوة الغاشمة) |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:**
  - `idx_otp_lookup` على `(organization_id, phone, purpose, consumed_at, expires_at)` — **مفتاح التقسيم**: كل بحث يُقيَّد بالثلاثي `(organization_id, phone, purpose)` فتبقى الفضاءات معزولة تماماً. `NULLS NOT DISTINCT` غير مطلوب لأن `organization_id=null` فضاء منصة مقصود منفصل.
  - `idx_otp_expiry_gc` على `(expires_at)` — كنس الرموز المنتهية (تنظيف دوري).
- **علاقات:** ينتمي لـ `organizations` (اختياري).

---

## 3. الإشعارات الصادرة (notifications)

### `notifications`  ← كان: `Notification`
> سجل الرسائل الصادرة (أو المحاولة) لعملاء المنشأة. **يكتبه تطبيق Next.js**؛ للقراءة فقط من هذا الـ API (بلا `$fillable`). لا عمود org — النطاق عبر `customer.organization_id`. بلا `updated_at`. منفصل عن `wa_messages` (قد يوجد ازدواج تسجيل لنفس الرسالة).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `customer_id` | bigint | لا | — | FK→`customers.id` ON DELETE CASCADE (النطاق org عبره) |
| `channel` | varchar(24) | لا | — | قناة الإرسال (whatsapp/sms/email…) |
| `template` | varchar(64) | نعم | — | مفتاح القالب المستخدم |
| `body` | text | نعم | — | النص المُرسَل |
| `status` | varchar(24) | لا | — | حالة الإرسال (سلسلة حرّة يكتبها Next.js) |
| `ref_id` | varchar(64) | نعم | — | مرجع (طلب/فاتورة…) |
| `sent_at` | timestamptz | نعم | — | — |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_notifications_customer` على `(customer_id, created_at DESC)` — سجل العميل الأحدث أولاً (حتى 100 صف).
- **علاقات:** ينتمي لـ `customers`.

---

## 4. مراسلة المشرفين الداخلية (conversations, conversation_participants, messages)

> **عالمية للمنصة** (بلا `organization_id`): مراسلة أدمن↔أدمن (`isPlatformOwner`)، 1:1 (DM) وقنوات (CHANNEL). الوصول participant-scoped مفروض في كل إجراء.

### `conversations`  ← كان: `Conversation`
| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `kind` | varchar(12) | لا | — | CHECK ∈ (DM, CHANNEL) |
| `title` | varchar(160) | نعم | — | اسم القناة (للـ CHANNEL) |
| `created_by_id` | bigint | لا | — | FK→`users.id` ON DELETE RESTRICT |
| `linked_type` | varchar(20) | نعم | — | CHECK ∈ (Organization, Lead, SupportTicket) — ربط اختياري بعنصر عمل |
| `linked_id` | bigint | نعم | — | مُعرّف العنصر المرتبط (polymorphic، لا FK صلب) |
| `linked_label` | varchar(200) | نعم | — | الاسم المُحلَّل خادمياً وقت الربط |
| `follow_up` | boolean | لا | false | علم متابعة |
| `resolved_at` | timestamptz | نعم | — | علم محلول |
| `last_message_at` | timestamptz | نعم | — | لترتيب الصندوق بالأحدث نشاطاً |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_conversations_activity` على `(last_message_at DESC)`؛ `idx_conversations_linked` على `(linked_type, linked_id)`.
- **علاقات:** ينتمي لـ `users` (المُنشئ)؛ يملك `conversation_participants`, `messages`.

### `conversation_participants`  ← كان: `ConversationParticipant`
> المصدر الوحيد للغير-مقروء وإيصالات القراءة. بلا `updated_at`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `conversation_id` | bigint | لا | — | FK→`conversations.id` ON DELETE CASCADE |
| `user_id` | bigint | لا | — | FK→`users.id` ON DELETE CASCADE |
| `last_read_at` | timestamptz | نعم | — | لحساب الغير-مقروء |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** **unique** `(conversation_id, user_id)` — عضو واحد لكل محادثة؛ `idx_participants_user` على `(user_id)` — صندوق المتصل.
- **علاقات:** ينتمي لـ `conversations`, `users`.

### `messages`  ← كان: `Message`
> رسائل الخيط. **حذف ناعم** عبر `deleted_at` (يبقى الخيط بشكل «رسالة محذوفة»). تعديل خلال نافذة 15 دقيقة عبر `edited_at`. بلا `updated_at`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `conversation_id` | bigint | لا | — | FK→`conversations.id` ON DELETE CASCADE |
| `author_id` | bigint | لا | — | FK→`users.id` ON DELETE RESTRICT |
| `body` | text | لا | — | — |
| `mentions` | jsonb | نعم | — | **JSON metadata**: مصفوفة مُعرّفات المذكورين (تُصفّى للأعضاء الفعليين) |
| `edited_at` | timestamptz | نعم | — | ختم التعديل |
| `deleted_at` | timestamptz | نعم | — | **حذف ناعم** |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_messages_thread` على `(conversation_id, created_at)` — الخيط بالترتيب الزمني.
- **علاقات:** ينتمي لـ `conversations`, `users` (المؤلّف).

---

## 5. الدعم (support_tickets, support_messages)

### `support_tickets`  ← كان: `SupportTicket`
> تذاكر دعم المنشأة (المنشأة ↔ أدمن المنصة). SLA و«آخر مؤلّف» يُحسبان حيّاً من الرسائل.
> **انحراف عن الأصل**: الأصل بلا عمود `category` (Prisma-owned) فكانت تُبأدأ في `subject` بين قوسين؛ في المخطّط الهدف أضفنا عمود `category` صريحاً (nullable) وننصح بترحيل القيمة من الـ subject.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `organization_id` | bigint | لا | — | FK→`organizations.id` ON DELETE CASCADE |
| `subject` | varchar(255) | لا | — | — |
| `category` | varchar(64) | نعم | — | من فئات المنصة (`support.categories`)؛ جديد في المخطّط الهدف |
| `status` | varchar(12) | لا | `'OPEN'` | CHECK ∈ (OPEN, PENDING, RESOLVED, CLOSED) |
| `priority` | varchar(12) | لا | `'NORMAL'` | CHECK ∈ (LOW, NORMAL, HIGH, URGENT) |
| `created_by_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL (مُنشئ المنشأة) |
| `assigned_to_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL (أدمن مُسنَد) |
| `last_reply_at` | timestamptz | نعم | — | لترتيب الصندوق بالأحدث نشاطاً |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_support_org` على `(organization_id, last_reply_at DESC)`؛ `idx_support_status` على `(status, last_reply_at DESC)` — صندوق الأدمن + عدّادات الحالة.
- **علاقات:** ينتمي لـ `organizations`, `users`؛ يملك `support_messages`.

### `support_messages`  ← كان: `SupportMessage`
> رسائل التذكرة. `authorType` يميّز جانب المنشأة عن الأدمن. بلا `updated_at`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `ticket_id` | bigint | لا | — | FK→`support_tickets.id` ON DELETE CASCADE |
| `author_type` | varchar(8) | لا | — | CHECK ∈ (TENANT, ADMIN) |
| `author_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL |
| `body` | text | لا | — | — |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_support_messages_ticket` على `(ticket_id, created_at)` — الخيط بالأقدم أولاً.
- **علاقات:** ينتمي لـ `support_tickets`, `users`.

---

## 6. CRM والمبيعات (crm_notes, leads)

### `crm_notes`  ← كان: `CrmNote`
> ملاحظات/مهام متابعة ضد منشأة أو عميل محتمل. بلا timestamps تلقائية (createdAt فقط). **يلزم أحدهما**: `lead_id` أو `organization_id`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `lead_id` | bigint | نعم | — | FK→`leads.id` ON DELETE CASCADE |
| `organization_id` | bigint | نعم | — | FK→`organizations.id` ON DELETE CASCADE |
| `kind` | varchar(12) | لا | `'NOTE'` | CHECK ∈ (NOTE, CALL, EMAIL, MEETING, TASK) |
| `body` | text | لا | — | — |
| `due_at` | timestamptz | نعم | — | موعد المهمة |
| `done_at` | timestamptz | نعم | — | إنجاز المهمة |
| `author_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:**
  - **CHECK** `(lead_id IS NOT NULL OR organization_id IS NOT NULL)` — لا ملاحظة معلّقة بلا مرجع.
  - `idx_crm_notes_lead` على `(lead_id, created_at DESC)`؛ `idx_crm_notes_org` على `(organization_id, created_at DESC)`؛ `idx_crm_notes_open_tasks` على `(due_at)` WHERE `done_at IS NULL` — قائمة المهام المفتوحة.
- **علاقات:** ينتمي لـ `leads`, `organizations`, `users`.

### `leads`  ← كان: `Lead`
> خطّ مبيعات المنشآت المرتقبة. **جدول منصة** (بلا `organization_id` — الـ Lead سابقٌ للمنشأة). عند التحويل يُملأ `converted_org_id` ويُوسم `WON`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `business_name` | varchar(200) | لا | — | — |
| `contact_name` | varchar(160) | نعم | — | — |
| `phone` | varchar(32) | نعم | — | — |
| `email` | varchar(190) | نعم | — | — |
| `city` | varchar(120) | نعم | — | — |
| `source` | varchar(64) | نعم | — | افتراضي من `marketing.defaultLeadSource` |
| `stage` | varchar(12) | لا | `'NEW'` | CHECK ∈ (NEW, CONTACTED, QUALIFIED, WON, LOST) |
| `expected_mrr` | numeric(12,2) | نعم | — | الإيراد الشهري المتوقّع |
| `owner_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL (المالك/مندوب) |
| `converted_org_id` | bigint | نعم | — | FK→`organizations.id` ON DELETE SET NULL — **يمنع التحويل المكرّر** |
| `lost_reason` | text | نعم | — | مطلوب منطقياً إن `stage=LOST` |
| `won_at` | timestamptz | نعم | — | يُختم عند أول دخول WON |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:**
  - **unique** `(converted_org_id)` WHERE `converted_org_id IS NOT NULL` — منشأة مُحوَّلة لا ترتبط بأكثر من lead.
  - `idx_leads_stage` على `(stage, created_at DESC)` — اللوحة + KPIs (open/wonThisMonth)؛ `idx_leads_owner` على `(owner_id)`.
- **علاقات:** ينتمي لـ `users` (المالك), `organizations` (المُحوَّلة)؛ يملك `crm_notes`.

---

## 7. المدوّنة (blog_categories, blog_posts) — عالمية

### `blog_categories`  ← كان: `BlogCategory`
| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `name` | varchar(160) | لا | — | عربي |
| `name_en` | varchar(160) | نعم | — | إنجليزي |
| `slug` | varchar(200) | لا | — | **unique** (يحفظ العربية) |
| `sort_order` | integer | لا | 0 | — |
| `is_active` | boolean | لا | true | — |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(slug)`؛ `idx_blog_categories_active` على `(is_active, sort_order)`.
- **علاقات:** يملك `blog_posts`.

### `blog_posts`  ← كان: `BlogPost`
> **عالمي** (بلا org). القراءة علنية للمنشورات `PUBLISHED` ذات `published_at` ماضٍ؛ الكتابة لمالك المنصة فقط.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `category_id` | bigint | نعم | — | FK→`blog_categories.id` ON DELETE SET NULL |
| `title` | varchar(255) | لا | — | عربي |
| `title_en` | varchar(255) | نعم | — | إنجليزي |
| `slug` | varchar(255) | لا | — | **unique** (يلحق -2,-3… للتفرّد، يحفظ العربية) |
| `excerpt` | text | نعم | — | مقتطف عربي |
| `excerpt_en` | text | نعم | — | مقتطف إنجليزي |
| `content` | text | نعم | — | محتوى عربي |
| `content_en` | text | نعم | — | محتوى إنجليزي |
| `cover_image_url` | varchar(500) | نعم | — | — |
| `tags` | text[] | نعم | — | مصفوفة Postgres (parse/toPgArray يدوي) |
| `status` | varchar(12) | لا | `'DRAFT'` | CHECK ∈ (DRAFT, PUBLISHED, ARCHIVED) |
| `published_at` | timestamptz | نعم | — | يُختم عند أول نشر |
| `created_by_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL |
| `view_count` | integer | لا | 0 | يُرفَع عند العرض |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(slug)`؛ `idx_blog_posts_published` على `(status, published_at DESC)` — قائمة علنية؛ `idx_blog_posts_category` على `(category_id)`.
- **علاقات:** ينتمي لـ `blog_categories`, `users`.

---

## 8. المنتدى (forum_categories, forum_threads, forum_posts) — عالمي

### `forum_categories`  ← كان: `ForumCategory`
| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `name` | varchar(160) | لا | — | عربي |
| `name_en` | varchar(160) | نعم | — | إنجليزي |
| `slug` | varchar(200) | لا | — | **unique** |
| `description` | text | نعم | — | — |
| `sort_order` | integer | لا | 0 | — |
| `is_active` | boolean | لا | true | — |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(slug)`؛ `idx_forum_categories_active` على `(is_active, sort_order)`.
- **علاقات:** يملك `forum_threads`.

### `forum_threads`  ← كان: `ForumThread`
> **عالمي**؛ `organization_id` للإسناد فقط (nullable). موضوع جديد يبدأ `PENDING` حتى موافقة مشرف. حدّ 3 مواضيع PENDING للمؤلّف.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `organization_id` | bigint | نعم | — | FK→`organizations.id` ON DELETE SET NULL — **إسناد فقط، لا يقيّد الرؤية** |
| `category_id` | bigint | لا | — | FK→`forum_categories.id` ON DELETE RESTRICT (يجب أن يكون نشطاً) |
| `title` | varchar(255) | لا | — | — |
| `slug` | varchar(255) | لا | — | **unique** (يحفظ العربية) |
| `body` | text | لا | — | — |
| `author_type` | varchar(8) | لا | `'USER'` | CHECK ∈ (USER) |
| `author_id` | bigint | لا | — | FK→`users.id` ON DELETE CASCADE (الاسم فقط يُحَل، لا email/role) |
| `status` | varchar(12) | لا | `'PENDING'` | CHECK ∈ (PENDING, APPROVED) |
| `rejection_reason` | text | نعم | — | رفض ضمني عبر ملء هذا الحقل |
| `is_pinned` | boolean | لا | false | المثبّت أولاً |
| `is_closed` | boolean | لا | false | مغلق = لا ردود |
| `reply_count` | integer | لا | 0 | — |
| `view_count` | integer | لا | 0 | — |
| `last_activity_at` | timestamptz | نعم | — | لترتيب «الأحدث نشاطاً» |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(slug)`؛ `idx_forum_threads_feed` على `(status, is_pinned DESC, last_activity_at DESC)` — الموجز العام؛ `idx_forum_threads_author_pending` على `(author_id, status)` — عدّ حدّ طابور المراجعة (3)؛ `idx_forum_threads_category` على `(category_id)`.
- **علاقات:** ينتمي لـ `forum_categories`, `users`, `organizations` (إسناد)؛ يملك `forum_posts`.

### `forum_posts`  ← كان: `ForumPost`
> ردود الموضوع. post-moderated (تلقائياً `APPROVED`). تتطلّب موضوعاً APPROVED غير مغلق.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `thread_id` | bigint | لا | — | FK→`forum_threads.id` ON DELETE CASCADE |
| `author_type` | varchar(8) | لا | `'USER'` | CHECK ∈ (USER) |
| `author_id` | bigint | لا | — | FK→`users.id` ON DELETE CASCADE |
| `body` | text | لا | — | — |
| `status` | varchar(12) | لا | `'APPROVED'` | CHECK ∈ (PENDING, APPROVED) |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_forum_posts_thread` على `(thread_id, status, created_at)` — ردود الموضوع المعتمَدة بالترتيب.
- **علاقات:** ينتمي لـ `forum_threads`, `users`.

---

## 9. المحتوى (social_posts, org_announcements)

### `social_posts`  ← كان: `SocialPost`
> تقويم منشورات السوشال الخاصة **بالمنصة نفسها** (تسويق غسلة). **منصة** (بلا org). النشر الفعلي يدوي. `scheduled_at` يُخزَّن UTC صراحةً.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `title` | varchar(200) | لا | — | — |
| `platform` | varchar(12) | لا | — | CHECK ∈ (TWITTER, INSTAGRAM, WHATSAPP, TIKTOK, SNAPCHAT, LINKEDIN) |
| `kind` | varchar(12) | لا | — | CHECK ∈ (IMAGE, CAROUSEL, STORY, REEL, TEXT) |
| `caption` | text | نعم | — | — |
| `image_urls` | text[] | نعم | — | مصفوفة Postgres؛ سقف قابل للضبط (`content.maxImagesPerPost`، افتراضي 6)؛ JPEG/PNG ≤10MB تحت `social-posts/` |
| `scheduled_at` | timestamptz | نعم | — | موعد الجدولة (UTC) |
| `published_at` | timestamptz | نعم | — | يُختم عند أول نشر يدوي |
| `status` | varchar(12) | لا | `'DRAFT'` | CHECK ∈ (DRAFT, SCHEDULED, PUBLISHED) |
| `notes` | text | نعم | — | — |
| `created_by_id` | bigint | نعم | — | FK→`users.id` ON DELETE SET NULL |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_social_posts_schedule` على `(status, scheduled_at)` — التقويم (الأقرب جدولةً أولاً) + عدّادات.
- **علاقات:** ينتمي لـ `users` (المُنشئ).

### `org_announcements`  ← كان: `OrgAnnouncement`
> إعلانات المنشأة لعملائها (كاروسيل بوابة العميل). **منشأة** (org-scoped). الكتابة خلف requireManager. `image_url` رابط نصّي فقط (لا رفع). بلا `updated_at`.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `legacy_cuid` | varchar(30) | نعم | — | unique |
| `organization_id` | bigint | لا | — | FK→`organizations.id` ON DELETE CASCADE |
| `title` | varchar(200) | لا | — | — |
| `body` | text | نعم | — | — |
| `image_url` | varchar(500) | نعم | — | نصّي فقط (regex يقبل `http(s)://` أو `/`) |
| `is_active` | boolean | لا | true | — |
| `created_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** `idx_org_announcements_active` على `(organization_id, is_active, created_at DESC)` — كاروسيل البوابة (النشطة، الأحدث).
- **علاقات:** ينتمي لـ `organizations`.

---

## تحويلات من Setting-JSON

> كانت إعدادات المراسلة/الحصص تُخزَّن كـ JSON في جدول `Setting` key/value. نُطبِّعها هنا إلى جداول علائقية. **مصدر عدّ الحصة يبقى `wa_messages`** (لا يُخزَّن عدّاد) — هذه الجداول تخزّن **الحدود والتبديلات** فقط.

### `wa_org_limits`  ← كان: `Setting['wa.limits:{orgId}']`
> تجاوزات الحصص/التبديلات لكل منشأة (يديرها أدمن المنصة). المنشأة بلا صف تَرِث افتراضات سياسة المنصة (`orgMonthlyQuota=1000`).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `organization_id` | bigint | لا | — | FK→`organizations.id` ON DELETE CASCADE، **unique** |
| `enabled` | boolean | لا | true | مفتاح المنشأة من إدارة المنصة (طبقة 2 من الحارس) |
| `monthly_limit` | integer | لا | 0 | 0 = بلا حد (طبقة 7). يُقارَن بعدّ `wa_messages` |
| `categories` | jsonb | نعم | — | **JSON**: allow-list الفئات `{MARKETING:bool,…}` (طبقة 3) |
| `allowed_events` | jsonb | نعم | — | **JSON**: allow-list الأحداث `{eventKey:bool}` (طبقة 4) |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(organization_id)`.
- **علاقات:** ينتمي لـ `organizations`؛ يملك `wa_branch_limits`.

### `wa_branch_limits`  ← كان: `Setting['wa.limits:{orgId}'].branchLimits`
> حصّة شهرية لكل فرع (تفريغ خريطة `branchLimits: {branchId:int}`). الفرع بلا صف يرث `branchMonthlyQuota` من سياسة المنصة (0 = بلا حد، طبقة 8).

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `organization_id` | bigint | لا | — | FK→`organizations.id` ON DELETE CASCADE |
| `branch_id` | bigint | لا | — | FK→`branches.id` ON DELETE CASCADE |
| `monthly_limit` | integer | لا | 0 | 0 = بلا حد |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(organization_id, branch_id)`.
- **علاقات:** ينتمي لـ `organizations`, `branches`.

### `messaging_configs`  ← كان: `Setting['messaging.config:{orgId}']`
> إعدادات المرسِل لكل منشأة (وضع PLATFORM/CUSTOM + اعتماد + تبديلات الأحداث + قوالب legacy). التوكن **مشفّر** (`SecretValue`)، مخفي عن التسلسل.

| العمود | النوع | Null | افتراضي | FK / ملاحظات |
|--------|------|------|---------|--------------|
| `id` | bigint (identity) | لا | auto | PK |
| `organization_id` | bigint | لا | — | FK→`organizations.id` ON DELETE CASCADE، **unique** |
| `enabled` | boolean | لا | true | المفتاح الرئيسي للمنشأة (طبقة 6) |
| `whatsapp_mode` | varchar(12) | لا | `'PLATFORM'` | CHECK ∈ (PLATFORM, CUSTOM). CUSTOM يتطلّب token+phoneId غير فارغين وإلا يسقط لـ PLATFORM |
| `whatsapp_token` | text | نعم | — | **مشفّر (SecretValue)، hidden** — لا يُسلسَل أبداً |
| `whatsapp_phone_id` | varchar(64) | نعم | — | — |
| `events` | jsonb | نعم | — | **JSON**: تبديلات أحداث المنشأة `{eventKey:bool}` (طبقة 6ب) |
| `legacy_templates` | jsonb | نعم | — | **JSON**: خريطة قوالب legacy (`orderReady`,`otp`,`invoice→paymentLink`) — أولوية 3 في حل القالب |
| `created_at` | timestamptz | لا | now() | — |
| `updated_at` | timestamptz | لا | now() | — |

- **فهارس/قيود:** unique `(organization_id)`.
- **علاقات:** ينتمي لـ `organizations`.

> **ملاحظة نطاق**: مفاتيح مستوى المنصة العامة (`platformEnabled`، ساعات الهدوء `quietHours*`، سياسة `PlatformSettings::whatsapp()` الافتراضية، قوالب البريد `platform.emailTemplates`، فئات الدعم `support.categories`) تبقى في جدول إعدادات المنصة العام (خارج نطاق هذا الملف — يُغطّى في مخطّط `13-market-settings-audit`).

---

## ملاحظات ختامية

- **Affiliate / AffiliateReferral** (قسم §10 من الـ BRD) خارج قائمة جداول هذا المجال — تُصمَّم في مخطّط التسويق/العمولة لتفادي التكرار.
- **التنبيهات الحيّة** (`/alerts`, `/admin/notifications`) **بلا جداول** — مشتقّة حيّاً من بيانات قائمة (طلبات/اشتراكات/مخزون/تذاكر/leads)؛ لا شيء لتصميمه هنا.
- كل الأعمدة الزمنية `timestamptz`؛ التخزين UTC. `scheduled_at`/نوافذ التقارير تُحوَّل من `Asia/Riyadh` قبل الاستعلام.
