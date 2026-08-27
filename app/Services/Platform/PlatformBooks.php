<?php

namespace App\Services\Platform;

use App\Enum\Accounting\AccountTypeEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\DeviceSale;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\SubscriptionInvoice;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;

/**
 * The platform keeps its own double-entry books on a reserved organization, so the admin
 * console gets the same accounting and reporting surfaces any tenant has. That org is
 * excluded from every tenant-facing list ({@see Organization::scopeTenantsOnly}).
 *
 * There is no SUBSCRIPTION journal source; a subscription payment is recorded as
 * source=PAYMENT with ref_type=SubscriptionInvoice, which is what keeps posting
 * idempotent per invoice.
 */
class PlatformBooks
{
    /**
     * The reserved organization's fixed slug — a lookup key that also makes creation
     * race-safe through the unique slug index.
     */
    private const BOOKS_SLUG = 'platform-books';

    public function __construct(
        private readonly PlatformConfigStore $config,
        private readonly ChartOfAccountsService $chart,
        private readonly JournalPostingService $posting,
    ) {}

    /**
     * Resolve (creating once) the reserved platform-books organization, with its chart
     * seeded and the sales account relabelled for subscription revenue. Idempotent.
     */
    public function organization(): Organization
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => self::BOOKS_SLUG],
            ['name' => 'دفاتر المنصّة', 'default_currency' => 'SAR', 'tax_rate' => 15],
        );

        if ($this->config->platformBooksOrgId() !== $organization->getKey()) {
            $this->config->setPlatformBooksOrgId($organization->getKey());
        }

        $this->chart->ensureChartOfAccounts($organization->getKey());
        $this->relabelSalesAccount($organization->getKey());

        return $organization;
    }

    public function storedOrgId(): ?int
    {
        return $this->config->platformBooksOrgId();
    }

    /**
     * Post the revenue of an issued subscription invoice to the platform books:
     * Dr Bank (total) / Cr Subscription revenue (net) / Cr VAT payable (tax).
     * Idempotent on the invoice.
     */
    public function postRevenue(SubscriptionInvoice $invoice): JournalEntry
    {
        $organization = $this->organization();
        $orgId = $organization->getKey();

        $bank = $this->chart->systemAccount($orgId, SystemAccountEnum::Bank);
        $sales = $this->chart->systemAccount($orgId, SystemAccountEnum::Sales);
        $vat = $this->chart->systemAccount($orgId, SystemAccountEnum::VatPayable);

        $total = round((float) $invoice->total, 2);
        $net = round((float) $invoice->subtotal, 2);
        $tax = round((float) $invoice->vat, 2);

        return $this->posting->post([
            'organization_id' => $orgId,
            'source' => JournalSourceEnum::Payment,
            'ref_type' => 'SubscriptionInvoice',
            'ref_id' => $invoice->getKey(),
            'date' => $invoice->issued_at ?? $invoice->created_at,
            'memo' => __('api.subscription_revenue_memo', ['plan' => (string) $invoice->plan_name]),
            'lines' => [
                ['account_id' => $bank->getKey(), 'debit' => $total, 'credit' => 0],
                ['account_id' => $sales->getKey(), 'debit' => 0, 'credit' => $net],
                ['account_id' => $vat->getKey(), 'debit' => 0, 'credit' => $tax],
            ],
        ]);
    }

    /**
     * Post the revenue of an issued device sale: Dr Bank (total) / Cr Device sales (net) /
     * Cr VAT payable (tax). Idempotent on the sale.
     *
     * Hardware is credited to its own account rather than to subscription revenue, so the
     * SaaS income statement can tell recurring income from one-off.
     */
    public function postDeviceSale(DeviceSale $sale): JournalEntry
    {
        $organization = $this->organization();
        $orgId = $organization->getKey();

        $bank = $this->chart->systemAccount($orgId, SystemAccountEnum::Bank);
        $vat = $this->chart->systemAccount($orgId, SystemAccountEnum::VatPayable);
        $deviceSales = $this->deviceSalesAccount($orgId);

        return $this->posting->post([
            'organization_id' => $orgId,
            'source' => JournalSourceEnum::Payment,
            'ref_type' => 'DeviceSale',
            'ref_id' => $sale->getKey(),
            'date' => $sale->issued_at ?? $sale->created_at,
            'memo' => __('api.device_sale_memo', ['buyer' => (string) $sale->buyer_name]),
            'lines' => [
                ['account_id' => $bank->getKey(), 'debit' => round((float) $sale->total, 2), 'credit' => 0],
                ['account_id' => $deviceSales->getKey(), 'debit' => 0, 'credit' => round((float) $sale->subtotal, 2)],
                ['account_id' => $vat->getKey(), 'debit' => 0, 'credit' => round((float) $sale->vat, 2)],
            ],
        ]);
    }

    /**
     * The device-sales revenue account, created on first use.
     *
     * It is not part of the chart every tenant is seeded with — only the platform sells
     * hardware — so it is added here rather than to the core catalogue.
     */
    private function deviceSalesAccount(int $orgId): Account
    {
        return Account::query()->firstOrCreate(
            ['organization_id' => $orgId, 'system_key' => SystemAccountEnum::DeviceSales->value],
            [
                'code' => '4120',
                'name' => 'إيرادات بيع الأجهزة',
                'name_en' => 'Device Sales',
                'type' => AccountTypeEnum::Revenue->value,
                'is_system' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * On the platform books the sales account carries subscription revenue, not laundry
     * revenue. Renaming is idempotent.
     */
    private function relabelSalesAccount(int $orgId): void
    {
        $sales = Account::query()
            ->forOrganization($orgId)
            ->systemKey(SystemAccountEnum::Sales)
            ->first();

        if ($sales !== null && $sales->name !== 'إيرادات الاشتراكات') {
            $sales->forceFill(['name' => 'إيرادات الاشتراكات', 'name_en' => 'Subscription Revenue'])->save();
        }
    }
}
