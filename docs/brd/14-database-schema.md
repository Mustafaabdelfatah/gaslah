# BRD 14 — المخطط النضيف المقترح لقاعدة البيانات

> تصميم قاعدة بيانات **نضيف من الصفر** لمنصة غسلة — يحلّ محل قاعدة Prisma المشتركة المجمّدة القديمة. الهدف: بنية علائقية سليمة، **كل حاجة كانت تُخزَّن كـ JSON في جدول `Setting` تتحوّل لجداول حقيقية**، مع الحفاظ على كل قواعد البيزنس والقيود من حزمة الـ BRD.
>
> **هذا الملف الرئيسي** يحدّد الاصطلاحات والأنماط العامة. **تفاصيل جداول كل مجال** في مجلد [`schema/`](schema/) (ملف لكل مجال). اقرأ هذا الملف أولاً ثم ملفات المجالات.

---

## 1. مبادئ التصميم

1. **علائقي أولاً:** كل كيان = جدول بأعمدة صريحة. الـ JSON يُستخدم فقط للبيانات غير المنظّمة فعلاً (metadata، snapshots، متغيّرات قوالب).
2. **لا `Setting` كمخزن كيانات:** في النظام القديم كان جدول `Setting` (key-value) يخزّن عشرات الكيانات كـ JSON بسبب تجميد الـ schema. هنا كل واحد بقى جدولاً حقيقياً. يبقى جدول `settings` عام صغير فقط للإعدادات الحرّة غير المنظّمة.
3. **العزل متعدد المنشآت مفروض على مستوى الـ schema:** `organization_id` على كل جدول مملوك لمنشأة، ومفتاح أجنبي حقيقي، وفهرس مركّب يبدأ به.
4. **القيود في قاعدة البيانات لا في التطبيق فقط:** الفرادة، المفاتيح الأجنبية، الفهارس الجزئية، وقيود CHECK — كلها تُفرض في الـ DDL.
5. **النزاهة المالية مدعومة بالـ schema:** فهرس الـ idempotency على القيود المحاسبية، الفهارس الجزئية الفريدة (وردية/تسوية واحدة مفتوحة)، `balance_after` على حركات المحفظة — كلها قيود جدول.

---

## 2. الاصطلاحات (Conventions)

| البند | القاعدة |
|---|---|
| **أسماء الجداول** | `snake_case` جمع: `orders`, `order_items`, `wallet_transactions` |
| **أسماء الأعمدة** | `snake_case`: `order_no`, `grand_total`, `branch_id` |
| **المفتاح الأساسي** | `id` — `bigint unsigned auto-increment` |
| **المفاتيح الأجنبية** | `{singular}_id` (مثل `customer_id`) — bigint، FK حقيقي مع `onDelete` مناسب |
| **معرّف قديم (اختياري)** | `legacy_cuid` — `string nullable unique` على الجداول التي قد تُستورد بياناتها من النظام القديم |
| **الطوابع** | `created_at` / `updated_at` (nullable timestamps). طوابع سير مخصّصة حيث يلزم (`opened_at`, `assigned_at`, `delivered_at`) |
| **الحذف الناعم** | `deleted_at` حيث يذكر الـ BRD "تعطيل/حذف ناعم" (users, messages, services عبر `is_active`) |
| **الأموال** | `decimal(14,2)` |
| **النِسَب** | `decimal(5,2)` (0–100) |
| **الكميات** | `decimal(12,2)` للكميات القابلة للكسر، `integer` للعدّاد الصحيح (نقاط، حصص قطع) |
| **الحالات/الأنواع (enums)** | عمود `string` مدعوم بـ **PHP enum** + قيد `CHECK` بقائمة القيم. (بديل: Postgres enum types — لكن string+CHECK أمرن للإضافة) |
| **JSON** | `jsonb` — للـ metadata/snapshots فقط |
| **البوليان** | `boolean` بأسماء `is_*` / `has_*` |
| **الأسرار** | أعمدة مشفّرة at-rest (Laravel `encrypted` cast)، لا تُرجَع للواجهة أبداً |

---

## 3. استراتيجية المفاتيح والعلاقات

- **PK:** `bigint auto-increment` (أبسط وأسرع للفهرسة من UUID/cuid). لو احتجت معرّفات عامة للمزامنة الموزّعة لاحقاً، أضف عمود `ulid`/`uuid` ثانوياً فريداً — لكن ابدأ بـ bigint.
- **الاستيراد من النظام القديم:** لو هتنقل بيانات من قاعدة Prisma القديمة (مفاتيح cuid نصية)، أضف `legacy_cuid` nullable unique على الجداول المعنية لتربط الصف الجديد بالقديم أثناء الترحيل، ثم يمكن إسقاطه لاحقاً.
- **onDelete:** استخدم `restrict` افتراضياً للحفاظ على السلامة المالية/الفاتورية؛ `cascade` فقط للتفاصيل التابعة بحق (order_items مع order، journal_lines مع journal_entry)؛ `set null` للمراجع الاختيارية (driver_id على طلب توصيل).

---

## 4. نمط تعدد المنشآت

- كل جدول مملوك لمنشأة يحمل `organization_id` (FK → organizations)، والجداول المقيّدة بالفرع تحمل `branch_id` كذلك.
- **Global Scope تلقائي** في طبقة التطبيق يقيّد كل استعلام بـ `organization_id` الحالي (من التوكن) — لكن الـ schema هو خط الدفاع الأخير.
- الجداول العالمية للمنصة (المنتدى، المدوّنة، الخطط، إعلانات المنصة) تسمح بـ `organization_id NULL` أو لا تحمله أصلاً — موضّح في ملف كل مجال.
- **فهرس مركّب** يبدأ بـ `organization_id` على كل جدول تينانت لأداء العزل (`(organization_id, status)`, `(organization_id, created_at)`…).

---

## 5. الجداول التي حلّت محل مخزن `Setting` (JSON → جداول)

كل هذه كانت مفاتيح JSON في جدول `Setting` بصيغة `{type}:{orgId}:{id}` — الآن جداول حقيقية:

| مخزن Setting القديم | الجدول الجديد | الملف |
|---|---|---|
| `user.permissions:{id}` | `user_permission_overrides` | [schema/01](schema/01-identity-tenancy.md) |
| صلاحيات/توكنات مبطلة | `token_denylist` / `personal_access_tokens` | [schema/01](schema/01-identity-tenancy.md) |
| `credit.config` | إعداد ائتمان العميل (أعمدة على customers أو جدول) | [schema/03](schema/03-catalog-customers.md) |
| `automation.config` | `automation_settings` | [schema/04](schema/04-orders-pos.md) / [07](schema/07-operations.md) |
| `bankrecon` | `bank_reconciliation_*` | [schema/07](schema/07-operations.md) |
| `budget:*`, `payable:*`, `recurring:*`, `assets:*` | `budgets`, `payables`, `recurring_payables`, `fixed_assets` | [schema/06](schema/06-accounting.md) |
| قفل الفترة | `books_locks` | [schema/06](schema/06-accounting.md) |
| `wa.limits`, `messaging.config` | `messaging_quotas`, `messaging_settings` | [schema/09](schema/09-messaging-content.md) |
| `zatca.state:{orgId}` | `zatca_registrations` | [schema/10](schema/10-market-zatca-settings.md) |
| أسرار التكاملات | `integration_secrets` (مشفّرة) | [schema/10](schema/10-market-zatca-settings.md) |
| `PlatformConfig` singleton | `platform_configs` | [schema/02](schema/02-platform-billing.md) |
| `hr.cost:{userId}` | عمود/جدول تكلفة الموظف | [schema/01](schema/01-identity-tenancy.md) |
| إعدادات المنشأة/التوصيل | أعمدة على organizations / `delivery_settings` | [schema/01](schema/01-identity-tenancy.md) / [08](schema/08-delivery-subs-loyalty.md) |

> يبقى جدول `settings` عام صغير (`key`, `value jsonb`, `organization_id`) **فقط** للإعدادات الحرّة النادرة غير المنظّمة — وليس لتخزين كيانات.

---

## 6. القيود الحرجة على مستوى الـ Schema (يجب فرضها)

هذه ليست اختيارية — هي التي تحمي نزاهة الفلوس والبيانات:

1. **idempotency محاسبي:** فهرس فريد على `journal_entries (organization_id, source, ref_type, ref_id)` حيث `ref_id IS NOT NULL`.
2. **رقم القيد:** فريد `journal_entries (organization_id, entry_no)`.
3. **وردية مفتوحة واحدة لكل كاشير:** فهرس **جزئي** فريد `shifts (user_id)` حيث `closed_at IS NULL`.
4. **تسوية مفتوحة واحدة لكل منشأة:** فهرس جزئي فريد `payout_settlements (organization_id)` حيث `status IN (open states)`.
5. **صوت واحد لكل أدمن لكل تسوية:** فريد `settlement_approvals (settlement_id, admin_id)`.
6. **idempotency الطلب:** فريد `orders (branch_id, client_request_id)` حيث `client_request_id IS NOT NULL`؛ و`orders.barcode` فريد؛ و`orders (branch_id, order_no)` فريد.
7. **idempotency الدفع الأونلاين:** فريد على مرجع بوابة الدفع في `payments`/`online_charges`.
8. **سلسلة ZATCA:** فريد `zatca_invoices (organization_id, icv)`؛ و`zatca_invoices.order_id` فريد.
9. **حساب ولاء واحد لكل عميل:** فريد `loyalty_accounts (customer_id)`.
10. **هاتف السائق فريد عالمياً** (الدخول بالهاتف). **إيميل المستخدم فريد عالمياً**.
11. **رصيد المحفظة:** `customers.wallet_balance` يُحدَّث فقط تحت قفل صف (`FOR UPDATE`)؛ `wallet_transactions.balance_after` يسجّل الرصيد بعد كل حركة للتدقيق.

---

## 7. enums المركزية (نظرة سريعة — التفاصيل في ملفات المجالات)

| Enum | القيم |
|---|---|
| دور الموظف | RECEPTION, CASHIER, BRANCH_MANAGER, SUPER_ADMIN |
| دور المنصة | OWNER, SUPPORT, SALES, FINANCE, VIEWER |
| نوع التوكن | staff, platform, customer, supplier, driver, affiliate, pos-otp |
| حالة الطلب | RECEIVED, PROCESSING, READY, DELIVERED, CANCELLED |
| أولوية الطلب | NORMAL, EXPRESS |
| حالة الدفع | UNPAID, PARTIAL, PAID, DEFERRED |
| طريقة الدفع | CASH, CARD, TRANSFER, WALLET, DEFERRED |
| نوع حركة المحفظة | TOPUP, DEBIT, REFUND |
| نوع الخدمة | WASH, IRON, WASH_IRON |
| نوع الحساب | ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE |
| مصدر القيد | MANUAL, ORDER, PAYMENT, REFUND, EXPENSE, WALLET_TOPUP, OPENING |
| حالة التسوية | PENDING_APPROVAL, APPROVED, SENT, REJECTED, CANCELLED |
| دورة الاشتراك | MONTHLY, QUARTERLY, YEARLY |
| نوع خطة الاشتراك | PIECE_QUOTA, PREPAID_BALANCE, UNLIMITED_SERVICE |
| حالة اشتراك العميل | ACTIVE, FROZEN |
| حالة اشتراك المنصة | TRIAL, ACTIVE, PAST_DUE, CANCELLED |
| نوع الكوبون | PERCENT, FIXED, FREE_MONTHS |
| حالة التوصيل (PICKUP) | REQUESTED, ASSIGNED, PICKED_UP, AT_FACILITY, CANCELLED |
| حالة التوصيل (DELIVERY) | REQUESTED, ASSIGNED, PICKED_UP, OUT_FOR_DELIVERY, DELIVERED, CANCELLED |
| حالة رسالة واتساب | QUEUED, SENT, DELIVERED, READ, FAILED, BLOCKED |
| غرض OTP | PORTAL_LOGIN, POS_WALLET, DRIVER_LOGIN, AFFILIATE_LOGIN, ORDER_PAYMENT |
| حالة مورّد السوق | PENDING, APPROVED, REJECTED, SUSPENDED |
| حالة طلب السوق | PENDING, CONFIRMED, SHIPPED, DELIVERED, CANCELLED |
| حالة فاتورة ZATCA | GENERATED, REPORTED |

> **مرجعية:** ملفات `schema/` هي المصدر المعتمد لقائمة القيم الكاملة والدقيقة لكل enum.

---

## 8. فهرس ملفات المخطط

| # | الملف | الجداول |
|---|---|---|
| 01 | [schema/01-identity-tenancy](schema/01-identity-tenancy.md) | organizations, branches, users, user_branches, الأدوار/الصلاحيات, token_denylist, security_logs |
| 02 | [schema/02-platform-billing](schema/02-platform-billing.md) | platform_plans, platform_subscriptions, org_add_ons, coupons, subscription_invoices, devices, partners, platform_configs |
| 03 | [schema/03-catalog-customers](schema/03-catalog-customers.md) | service_categories, products, services, garment_types, units, customers, customer_addresses |
| 04 | [schema/04-orders-pos](schema/04-orders-pos.md) | orders, order_items, order_status_histories |
| 05 | [schema/05-payments-wallet](schema/05-payments-wallet.md) | payments, wallet_transactions, online_charges, payout_settlements, settlement_approvals |
| 06 | [schema/06-accounting](schema/06-accounting.md) | accounts, journal_entries, journal_lines, expenses, fixed_assets, budgets, payables, books_locks |
| 07 | [schema/07-operations](schema/07-operations.md) | inventory_items, suppliers, purchase_orders, shifts, cash_movements, bank_reconciliation |
| 08 | [schema/08-delivery-subs-loyalty](schema/08-delivery-subs-loyalty.md) | delivery_*, drivers, subscriptions, subscription_plans, loyalty_* |
| 09 | [schema/09-messaging-content](schema/09-messaging-content.md) | wa_messages, otp_codes, notifications, conversations, support_*, crm_notes, blog_*, forum_*, affiliates |
| 10 | [schema/10-market-zatca-settings](schema/10-market-zatca-settings.md) | market_*, zatca_invoices, zatca_registrations, audit_logs, integration_secrets, settings |

---

## 9. ملاحظات للترحيل (لو هتنقل بيانات قديمة)
- أضف `legacy_cuid` على الجداول اللي هتستورد بياناتها، واملأه بمفتاح cuid القديم أثناء الترحيل.
- الكيانات المخزّنة في `Setting` قديماً تُقرأ بمفتاحها `{type}:{orgId}:{id}` وتُفكَّك لأعمدة الجدول الجديد.
- أرصدة المحفظة والحسابات: أعد احتسابها من حركات `wallet_transactions` و`journal_lines` بدل نسخ الرصيد المخزّن (تحقّق من التطابق).
- **لا تنقل** الأسرار كنص صريح — أعد إدخالها مشفّرة في `integration_secrets`.
