<?php

use App\Http\Controllers\API\Affiliate\AffiliateAuthController;
use App\Http\Controllers\API\Affiliate\AffiliateController;
use App\Http\Controllers\API\Auth\LoginController;
use App\Http\Controllers\API\Auth\LogoutController;
use App\Http\Controllers\API\Auth\OTPController;
use App\Http\Controllers\API\Auth\ResetPasswordController;
use App\Http\Controllers\API\Auth\SignupController;
use App\Http\Controllers\API\DataEntry\CountryController;
use App\Http\Controllers\API\Driver\DriverAuthController;
use App\Http\Controllers\API\Driver\DriverController;
use App\Http\Controllers\API\Global\ActivityLog\ActivityLogController;
use App\Http\Controllers\API\Global\Captcha\CaptchaController;
use App\Http\Controllers\API\Global\Chunk\ChunkFileController;
use App\Http\Controllers\API\Global\Help\HelpController;
use App\Http\Controllers\API\Global\Notification\NotificationController;
use App\Http\Controllers\API\Global\Report\ReportController;
use App\Http\Controllers\API\Global\Setting\SettingController;
use App\Http\Controllers\API\Global\Setting\TestCredentialsController;
use App\Http\Controllers\API\Messaging\WaWebhookController;
use App\Http\Controllers\API\Payments\PayController;
use App\Http\Controllers\API\Platform\AdminAnnouncementController;
use App\Http\Controllers\API\Platform\AdminCouponController;
use App\Http\Controllers\API\Platform\AdminDunningController;
use App\Http\Controllers\API\Platform\AdminInvoiceController;
use App\Http\Controllers\API\Platform\AdminPayoutController;
use App\Http\Controllers\API\Platform\AdminPlanController;
use App\Http\Controllers\API\Platform\AdminSubscriptionController;
use App\Http\Controllers\API\Platform\AdminTenantController;
use App\Http\Controllers\API\Platform\PlatformStatsController;
use App\Http\Controllers\API\Portal\PortalAuthController;
use App\Http\Controllers\API\Portal\PortalController;
use App\Http\Controllers\API\Portal\PortalDeliveryController;
use App\Http\Controllers\API\Profile\ProfileController;
use App\Http\Controllers\API\Tenancy\Accounting\AccountController;
use App\Http\Controllers\API\Tenancy\Accounting\AccountingReportController;
use App\Http\Controllers\API\Tenancy\Accounting\AssetController;
use App\Http\Controllers\API\Tenancy\Accounting\BooksLockController;
use App\Http\Controllers\API\Tenancy\Accounting\ExpenseController;
use App\Http\Controllers\API\Tenancy\Accounting\JournalController;
use App\Http\Controllers\API\Tenancy\Auth\PlatformLoginController;
use App\Http\Controllers\API\Tenancy\Auth\StaffLoginController;
use App\Http\Controllers\API\Tenancy\Catalog\CatalogController;
use App\Http\Controllers\API\Tenancy\Catalog\CustomerController;
use App\Http\Controllers\API\Tenancy\Community\CommunityController;
use App\Http\Controllers\API\Tenancy\Community\ForumController;
use App\Http\Controllers\API\Tenancy\Delivery\DeliveryController;
use App\Http\Controllers\API\Tenancy\Delivery\DeliveryPhotoController;
use App\Http\Controllers\API\Tenancy\Delivery\DisplayController;
use App\Http\Controllers\API\Tenancy\Inventory\InventoryController;
use App\Http\Controllers\API\Tenancy\Inventory\SupplierController;
use App\Http\Controllers\API\Tenancy\Loyalty\LoyaltyController;
use App\Http\Controllers\API\Tenancy\Messaging\AlertsController;
use App\Http\Controllers\API\Tenancy\Messaging\NotificationLogController;
use App\Http\Controllers\API\Tenancy\Messaging\OrgAnnouncementController;
use App\Http\Controllers\API\Tenancy\Messaging\WaController;
use App\Http\Controllers\API\Tenancy\Orders\AutomationController;
use App\Http\Controllers\API\Tenancy\Orders\OrderController;
use App\Http\Controllers\API\Tenancy\Orders\PosController;
use App\Http\Controllers\API\Tenancy\Payments\PayoutController;
use App\Http\Controllers\API\Tenancy\Platform\OrgNoticeController;
use App\Http\Controllers\API\Tenancy\Platform\OrgSubscriptionController;
use App\Http\Controllers\API\Tenancy\Reports\AnalyticsController;
use App\Http\Controllers\API\Tenancy\Reports\BankController;
use App\Http\Controllers\API\Tenancy\Reports\DashboardController;
use App\Http\Controllers\API\Tenancy\Reports\ReportController as SalesReportController;
use App\Http\Controllers\API\Tenancy\Reports\ShiftController;
use App\Http\Controllers\API\Tenancy\StaffContextController;
use App\Http\Controllers\API\Tenancy\Subscriptions\SubscriptionController;
use App\Http\Controllers\API\Tenancy\Subscriptions\SubscriptionPlanController;
use App\Http\Controllers\API\Tenancy\Zatca\ZatcaController;
use App\Http\Controllers\API\Tenancy\Zatca\ZatcaPhase2Controller;
use App\Http\Controllers\API\User\PermissionController;
use App\Http\Controllers\API\User\RoleController;
use App\Http\Controllers\API\User\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (Guest Accessible)
|--------------------------------------------------------------------------
*/
Route::prefix('captcha')->group(function () {
    Route::get('/', [CaptchaController::class, 'generateCaptcha']);
    Route::post('/verify', [CaptchaController::class, 'verifyCaptcha']);
});

/*
|--------------------------------------------------------------------------
| Auth Routes (Public)
|--------------------------------------------------------------------------
*/
Route::post('login', LoginController::class);
Route::post('reset-password', ResetPasswordController::class);

// Gaslah — public self-service tenant signup.
Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| Gaslah — Tenant & Platform Auth (Public)
|--------------------------------------------------------------------------
| Two separate surfaces on the shared User model: staff sign in to their
| organization, platform operators sign in to the admin console. A tenant
| account is refused at the platform surface even with a valid password.
*/
Route::post('staff/login', StaffLoginController::class);
Route::post('platform/auth/login', PlatformLoginController::class);

// OTP Routes (Public for login/registration)
Route::post('send-otp', [OTPController::class, 'send']);
Route::post('check-otp', [OTPController::class, 'check']);
Route::post('verify-otp', [OTPController::class, 'verify']);

// Gaslah — Delivery public surfaces: the in-store display board (signed branch
// token) and proof photos (time-limited signed URL).
Route::get('display/{token}', [DisplayController::class, 'show']);
Route::get('delivery/photos/{name}', [DeliveryPhotoController::class, 'show'])
    ->name('delivery.photo')
    ->middleware('signed');

// Gaslah — Public payment page (signed pay token) and the Moyasar webhook. The
// token is the only capability; the amount is recomputed server-side.
Route::get('pay/{token}', [PayController::class, 'show']);
Route::post('pay/{token}', [PayController::class, 'pay']);
Route::post('payments/webhook', [PayController::class, 'webhook']);

// Gaslah — WhatsApp webhook (Meta verify token + HMAC fail-closed).
Route::get('wa/webhook', [WaWebhookController::class, 'verify']);
Route::post('wa/webhook', [WaWebhookController::class, 'receive']);

/*
|--------------------------------------------------------------------------
| Gaslah — Affiliate surface (phone + OTP, kind=affiliate)
|--------------------------------------------------------------------------
*/
Route::get('r/{code}', [AffiliateAuthController::class, 'landing']);
Route::prefix('affiliate')->group(function () {
    Route::post('auth/register', [AffiliateAuthController::class, 'register']);
    Route::post('auth/request-otp', [AffiliateAuthController::class, 'requestOtp']);
    Route::post('auth/verify-otp', [AffiliateAuthController::class, 'verifyOtp']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AffiliateController::class, 'me']);
        Route::get('referrals', [AffiliateController::class, 'referrals']);
    });
});

Route::middleware(['auth:sanctum'])->group(function () {
    /*
   |--------------------------------------------------------------------------
   | Profile Routes
   |--------------------------------------------------------------------------
   */
    Route::get('me', [ProfileController::class, 'user']);

    // Gaslah: the signed-in staff member's live tenant context.
    Route::get('staff/context', StaffContextController::class);
    Route::post('update-setting', [ProfileController::class, 'updateSetting']);
    Route::post('update-profile', [ProfileController::class, 'updateProfile']);
    Route::post('destroy-avatar', [ProfileController::class, 'destroyAvatar']);
    Route::post('logout', LogoutController::class);

    /*
    |--------------------------------------------------------------------------
    | Roles && Permissions Routes
    |--------------------------------------------------------------------------
    */
    Route::get('permissions', [PermissionController::class, 'index']);

    Route::prefix('roles')->group(function () {
        Route::delete('delete', [RoleController::class, 'destroy']);
        Route::apiResource('/', RoleController::class)->parameters(['' => 'role'])->except(['destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Data Entry Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('countries')->group(function () {
        Route::delete('force-delete', [CountryController::class, 'forceDelete']);
        Route::delete('delete', [CountryController::class, 'destroy']);
        Route::post('restore', [CountryController::class, 'restore']);
        Route::put('toggle-active', [CountryController::class, 'toggleActive']);
        Route::apiResource('/', CountryController::class)->parameters(['' => 'country'])->except(['destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Global Routes
    |--------------------------------------------------------------------------
    */
    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);

    Route::post('send-test-mail', [TestCredentialsController::class, 'testEmail']);

    Route::get('report', ReportController::class);

    Route::get('activity-logs', [ActivityLogController::class, 'index']);
    Route::get('activity-logs/{activity}', [ActivityLogController::class, 'show']);

    Route::get('help-configs', [HelpController::class, 'configs']);
    Route::get('help-models', [HelpController::class, 'models']);
    Route::get('help-enums', [HelpController::class, 'enums']);

    Route::put('notifications', [NotificationController::class, 'update']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('chunk-file', ChunkFileController::class);

    /*
    |--------------------------------------------------------------------------
    | User Routes (Admin can manage users)
    |--------------------------------------------------------------------------
    */
    Route::prefix('users')->group(function () {
        Route::delete('force-delete', [UserController::class, 'forceDelete']);
        Route::delete('delete', [UserController::class, 'destroy']);
        Route::post('restore', [UserController::class, 'restore']);
        Route::put('toggle-active', [UserController::class, 'toggleActive']);
        Route::apiResource('/', UserController::class)->parameters(['' => 'user'])->except(['destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Accounting (staff, manager-gated)
    |--------------------------------------------------------------------------
    */
    Route::prefix('accounting')->group(function () {
        // Chart of accounts
        Route::get('accounts', [AccountController::class, 'index']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::patch('accounts/{account}', [AccountController::class, 'update']);

        // Journal
        Route::get('journal', [JournalController::class, 'index']);
        Route::post('journal', [JournalController::class, 'store']);
        Route::get('journal/{journal}', [JournalController::class, 'show']);
        Route::post('journal/{journal}/reverse', [JournalController::class, 'reverse']);

        // Reports
        Route::get('trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('income-statement', [AccountingReportController::class, 'incomeStatement']);
        Route::get('balance-sheet', [AccountingReportController::class, 'balanceSheet']);
        Route::get('vat-return', [AccountingReportController::class, 'vatReturn']);
        Route::get('ledger/{account}', [AccountingReportController::class, 'ledger']);

        // Expenses
        Route::get('expenses', [ExpenseController::class, 'index']);
        Route::post('expenses', [ExpenseController::class, 'store']);
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);

        // Period lock
        Route::get('period-lock', [BooksLockController::class, 'show']);
        Route::put('period-lock', [BooksLockController::class, 'update']);
    });

    // Fixed assets
    Route::prefix('assets')->group(function () {
        Route::get('/', [AssetController::class, 'index']);
        Route::post('/', [AssetController::class, 'store']);
        Route::post('{asset}/depreciate', [AssetController::class, 'depreciate']);
        Route::post('{asset}/dispose', [AssetController::class, 'dispose']);
        Route::delete('{asset}', [AssetController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Catalog & Customers (staff)
    |--------------------------------------------------------------------------
    */
    Route::prefix('catalog')->group(function () {
        Route::get('/', [CatalogController::class, 'index']);
        Route::post('categories', [CatalogController::class, 'storeCategory']);
        Route::put('categories/reorder', [CatalogController::class, 'reorderCategories']);
        Route::post('products', [CatalogController::class, 'storeProduct']);
        Route::patch('products/{product}', [CatalogController::class, 'updateProduct']);
        Route::patch('products/{product}/code', [CatalogController::class, 'updateProductCode']);
        Route::put('services/{service}', [CatalogController::class, 'updateService']);
    });

    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('{customer}', [CustomerController::class, 'show']);
        Route::put('{customer}', [CustomerController::class, 'update']);
        Route::delete('{customer}', [CustomerController::class, 'destroy']);
        Route::post('{customer}/wallet/topup', [CustomerController::class, 'walletTopup']);
        Route::get('{customer}/wallet/transactions', [CustomerController::class, 'walletTransactions']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Orders & POS (staff)
    |--------------------------------------------------------------------------
    */
    Route::prefix('pos')->group(function () {
        Route::post('orders', [PosController::class, 'store']);
        Route::post('otp/request', [PosController::class, 'otpRequest']);
        Route::post('otp/verify', [PosController::class, 'otpVerify']);
    });

    // Order auto-advance automation settings.
    Route::get('automation', [AutomationController::class, 'show']);
    Route::put('automation', [AutomationController::class, 'update']);

    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('{order}', [OrderController::class, 'show']);
        Route::patch('{order}/status', [OrderController::class, 'updateStatus']);
        Route::post('{order}/payment-link', [OrderController::class, 'paymentLink']);

        // ZATCA e-invoicing: Phase 1 instant QR + Phase 2 stored UBL invoice.
        Route::get('{order}/invoice', [ZatcaController::class, 'invoice']);
        Route::post('{order}/zatca-invoice', [ZatcaPhase2Controller::class, 'store']);
        Route::get('{order}/zatca-invoice', [ZatcaPhase2Controller::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Customer Subscriptions (staff, feature: subscriptions)
    |--------------------------------------------------------------------------
    */
    Route::prefix('subscription-plans')->group(function () {
        Route::get('/', [SubscriptionPlanController::class, 'index']);
        Route::post('/', [SubscriptionPlanController::class, 'store']);
        Route::put('{plan}', [SubscriptionPlanController::class, 'update']);
    });

    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [SubscriptionController::class, 'index']);
        Route::post('/', [SubscriptionController::class, 'store']);
        Route::post('{subscription}/pay', [SubscriptionController::class, 'pay']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Loyalty (staff, feature: loyalty)
    |--------------------------------------------------------------------------
    */
    Route::prefix('loyalty')->group(function () {
        Route::get('program', [LoyaltyController::class, 'program']);
        Route::put('program', [LoyaltyController::class, 'updateProgram']);
        Route::get('accounts', [LoyaltyController::class, 'accounts']);
        Route::post('accounts/{customer}/adjust', [LoyaltyController::class, 'adjust']);
    });

    // Redeem points for wallet value — customer-scoped path per the spec.
    Route::post('customers/{customer}/loyalty/redeem', [LoyaltyController::class, 'redeem']);

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Delivery (staff, feature: delivery)
    |--------------------------------------------------------------------------
    */
    Route::prefix('delivery')->group(function () {
        Route::get('settings', [DeliveryController::class, 'settings']);
        Route::put('settings', [DeliveryController::class, 'updateSettings']);

        Route::get('zones', [DeliveryController::class, 'zones']);
        Route::post('zones', [DeliveryController::class, 'storeZone']);
        Route::put('zones/{zone}', [DeliveryController::class, 'updateZone']);

        Route::get('drivers', [DeliveryController::class, 'drivers']);
        Route::post('drivers', [DeliveryController::class, 'storeDriver']);
        Route::put('drivers/{driver}', [DeliveryController::class, 'updateDriver']);

        Route::get('stats', [DeliveryController::class, 'stats']);

        Route::get('requests', [DeliveryController::class, 'requests']);
        Route::post('requests', [DeliveryController::class, 'storeRequest']);
        Route::get('requests/{delivery}', [DeliveryController::class, 'showRequest']);
        Route::patch('requests/{delivery}', [DeliveryController::class, 'updateRequest']);
        Route::post('requests/{delivery}/action', [DeliveryController::class, 'requestAction']);
        Route::post('requests/{delivery}/inventory', [DeliveryController::class, 'inventory']);
    });

    // Mint an in-store display link for a branch.
    Route::post('display/token', [DisplayController::class, 'token']);

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Payouts (organization side)
    |--------------------------------------------------------------------------
    */
    Route::prefix('payouts')->group(function () {
        Route::get('/', [PayoutController::class, 'index']);
        Route::patch('config', [PayoutController::class, 'config']);
        Route::post('request', [PayoutController::class, 'request']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Reports (staff, read-only)
    |--------------------------------------------------------------------------
    */
    Route::prefix('reports')->group(function () {
        Route::get('sales', [SalesReportController::class, 'sales']);
        Route::get('top-products', [SalesReportController::class, 'topProducts']);
        Route::get('top-customers', [SalesReportController::class, 'topCustomers']);
        Route::get('cancellation-rate', [SalesReportController::class, 'cancellationRate']);
    });

    Route::get('analytics', [AnalyticsController::class, 'index']);
    Route::get('dashboard', [DashboardController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Gaslah — WhatsApp screen (staff, feature: messaging)
    |--------------------------------------------------------------------------
    */
    Route::prefix('wa')->group(function () {
        Route::get('overview', [WaController::class, 'overview']);
        Route::get('messages', [WaController::class, 'messages']);
        Route::get('templates', [WaController::class, 'templates']);
        Route::post('templates', [WaController::class, 'storeTemplate']);
        Route::put('templates/{template}', [WaController::class, 'updateTemplate']);
        Route::delete('templates/{template}', [WaController::class, 'deleteTemplate']);
        Route::post('test', [WaController::class, 'test']);
    });

    // Live operational alerts + outbound message log (a distinct path from the
    // framework's user-notifications route).
    Route::get('alerts', [AlertsController::class, 'index']);
    Route::get('notifications-log', [NotificationLogController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Community forum (staff, platform-wide)
    |--------------------------------------------------------------------------
    */
    // Organization's own platform subscription view.
    Route::get('org/entitlements', [OrgSubscriptionController::class, 'entitlements']);
    Route::get('org/subscription', [OrgSubscriptionController::class, 'subscription']);
    Route::get('org/notices', [OrgNoticeController::class, 'index']);

    Route::get('community', [CommunityController::class, 'feed']);
    Route::prefix('forum')->group(function () {
        Route::get('categories', [ForumController::class, 'categories']);
        Route::get('threads', [ForumController::class, 'threads']);
        Route::get('threads/{thread}', [ForumController::class, 'show']);
        Route::post('threads', [ForumController::class, 'storeThread']);
        Route::post('threads/{thread}/posts', [ForumController::class, 'storePost']);
    });

    // Organization announcements (portal carousel; writes manager-gated).
    Route::prefix('announcements')->group(function () {
        Route::get('/', [OrgAnnouncementController::class, 'index']);
        Route::post('/', [OrgAnnouncementController::class, 'store']);
        Route::put('{announcement}', [OrgAnnouncementController::class, 'update']);
        Route::delete('{announcement}', [OrgAnnouncementController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Inventory, Suppliers & Purchase Orders (feature: inventory)
    |--------------------------------------------------------------------------
    */
    Route::prefix('inventory')->group(function () {
        Route::get('items', [InventoryController::class, 'items']);
        Route::post('items', [InventoryController::class, 'storeItem']);
        Route::put('items/{item}', [InventoryController::class, 'updateItem']);
        Route::get('low-stock', [InventoryController::class, 'lowStock']);
        Route::get('purchase-orders', [InventoryController::class, 'purchaseOrders']);
    });

    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index']);
        Route::post('/', [SupplierController::class, 'store']);
        Route::put('{supplier}', [SupplierController::class, 'update']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Shifts (staff, permission: shifts.manage)
    |--------------------------------------------------------------------------
    */
    Route::prefix('shifts')->group(function () {
        Route::get('current', [ShiftController::class, 'current']);
        Route::get('/', [ShiftController::class, 'index']);
        Route::post('open', [ShiftController::class, 'open']);
        Route::post('close', [ShiftController::class, 'close']);
    });

    /*
    |--------------------------------------------------------------------------
    | Gaslah — Bank Reconciliation (manager, org-level)
    |--------------------------------------------------------------------------
    */
    Route::prefix('bank')->group(function () {
        Route::get('reconciliation', [BankController::class, 'reconciliation']);
        Route::post('clear', [BankController::class, 'clear']);
        Route::post('clear-all', [BankController::class, 'clearAll']);
        Route::post('statement-balance', [BankController::class, 'statementBalance']);
    });
});

/*
|--------------------------------------------------------------------------
| Gaslah — Payout settlements (platform admin, maker-checker)
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Gaslah — Platform admin console (kind=platform, live DB permission checks)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('stats', [PlatformStatsController::class, 'index']);

    Route::prefix('plans')->group(function () {
        Route::get('/', [AdminPlanController::class, 'index'])->middleware('platform.permission:manage_plans,view_finance');
        Route::get('{plan}', [AdminPlanController::class, 'show'])->middleware('platform.permission:manage_plans,view_finance');
        Route::post('/', [AdminPlanController::class, 'store'])->middleware('platform.permission:manage_plans');
        Route::put('{plan}', [AdminPlanController::class, 'update'])->middleware('platform.permission:manage_plans');
    });

    Route::get('activity', [AdminTenantController::class, 'activity'])->middleware('platform.permission:manage_tenants');

    Route::get('announcements', [AdminAnnouncementController::class, 'index']);
    Route::post('announcements', [AdminAnnouncementController::class, 'store']);
    Route::put('announcements/{announcement}', [AdminAnnouncementController::class, 'update']);
    Route::delete('announcements/{announcement}', [AdminAnnouncementController::class, 'destroy']);

    Route::prefix('tenants')->middleware('platform.permission:manage_tenants')->group(function () {
        Route::get('/', [AdminTenantController::class, 'index']);
        Route::get('{organization}', [AdminTenantController::class, 'show']);
        Route::get('{organization}/users', [AdminTenantController::class, 'users']);
        Route::post('{organization}/suspend', [AdminTenantController::class, 'suspend']);
        Route::patch('{organization}/entitlements', [AdminTenantController::class, 'updateEntitlements']);
    });

    Route::put('tenants/{organization}/subscription', [AdminSubscriptionController::class, 'update']);
    Route::post('tenants/{organization}/start-trial', [AdminSubscriptionController::class, 'startTrial']);
    Route::post('tenants/{organization}/extend', [AdminSubscriptionController::class, 'extend']);

    // Subscription billing — two-step ZATCA invoicing.
    Route::get('invoices', [AdminInvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [AdminInvoiceController::class, 'show']);
    Route::post('tenants/{organization}/invoices', [AdminInvoiceController::class, 'store']);
    Route::post('invoices/{invoice}/confirm', [AdminInvoiceController::class, 'confirm']);
    Route::delete('invoices/{invoice}', [AdminInvoiceController::class, 'destroy']);

    // Dunning — reminder/renewal/suspension cycle.
    Route::get('dunning', [AdminDunningController::class, 'index']);
    Route::put('dunning', [AdminDunningController::class, 'update']);
    Route::post('dunning/run', [AdminDunningController::class, 'run']);

    // Subscription coupons.
    Route::post('coupons/validate', [AdminCouponController::class, 'validateCode']);
    Route::get('coupons', [AdminCouponController::class, 'index']);
    Route::post('coupons', [AdminCouponController::class, 'store']);
    Route::put('coupons/{coupon}', [AdminCouponController::class, 'update']);
    Route::delete('coupons/{coupon}', [AdminCouponController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->prefix('admin/payouts')->group(function () {
    Route::get('/', [AdminPayoutController::class, 'index']);
    Route::post('/', [AdminPayoutController::class, 'store']);
    Route::get('balances', [AdminPayoutController::class, 'balances']);
    Route::post('run-due', [AdminPayoutController::class, 'runDue']);
    Route::get('settings', [AdminPayoutController::class, 'settings']);
    Route::patch('settings', [AdminPayoutController::class, 'updateSettings']);
    Route::get('{settlement}', [AdminPayoutController::class, 'show']);
    Route::post('{settlement}/approve', [AdminPayoutController::class, 'approve']);
    Route::post('{settlement}/reject', [AdminPayoutController::class, 'reject']);
    Route::patch('{settlement}/fee', [AdminPayoutController::class, 'fee']);
    Route::post('{settlement}/sent', [AdminPayoutController::class, 'sent']);
    Route::post('{settlement}/cancel', [AdminPayoutController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Gaslah — Driver App (phone + OTP, kind=driver)
|--------------------------------------------------------------------------
*/
Route::prefix('driver')->group(function () {
    Route::post('auth/request-otp', [DriverAuthController::class, 'requestOtp']);
    Route::post('auth/verify-otp', [DriverAuthController::class, 'verifyOtp']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [DriverController::class, 'me']);
        Route::get('requests', [DriverController::class, 'requests']);
        Route::post('requests/{id}/accept', [DriverController::class, 'accept']);
        Route::post('requests/{id}/reject', [DriverController::class, 'reject']);
        Route::post('requests/{id}/arrive', [DriverController::class, 'arrive']);
        Route::post('requests/{id}/photo', [DriverController::class, 'photo']);
        Route::post('requests/{id}/advance', [DriverController::class, 'advance']);
    });
});

/*
|--------------------------------------------------------------------------
| Gaslah — Customer Portal (phone + OTP, kind=customer)
|--------------------------------------------------------------------------
*/
Route::prefix('portal')->group(function () {
    Route::post('auth/request-otp', [PortalAuthController::class, 'requestOtp']);
    Route::post('auth/verify-otp', [PortalAuthController::class, 'verifyOtp']);
    Route::get('branding/{slug}', [PortalAuthController::class, 'branding']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [PortalController::class, 'me']);
        Route::get('orders', [PortalController::class, 'orders']);
        Route::get('orders/{id}', [PortalController::class, 'order']);

        Route::get('addresses', [PortalController::class, 'addresses']);
        Route::post('addresses', [PortalController::class, 'storeAddress']);
        Route::delete('addresses/{id}', [PortalController::class, 'destroyAddress']);

        Route::get('delivery', [PortalDeliveryController::class, 'index']);
        Route::post('delivery', [PortalDeliveryController::class, 'store']);
        Route::post('delivery/{id}/approve-invoice', [PortalDeliveryController::class, 'approveInvoice']);

        Route::get('announcements', [PortalController::class, 'announcements']);
    });
});
