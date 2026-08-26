<?php

namespace App\Services\Accounting;

use App\Enum\Accounting\AccountTypeEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seeds and resolves the system chart of accounts for an organization.
 *
 * The core accounts are seeded once per organization; the fixed-asset accounts are
 * added lazily the first time an asset operation needs them. Seeding is idempotent and
 * concurrency-tolerant: an existing account is skipped, and a duplicate-key race is
 * swallowed.
 */
class ChartOfAccountsService
{
    /**
     * The default chart seeded for every organization.
     *
     * @var array<int, array{code: string, name: string, name_en: string, type: AccountTypeEnum, key: SystemAccountEnum}>
     */
    private const CORE_ACCOUNTS = [
        ['code' => '1010', 'name' => 'الصندوق (نقدية)', 'name_en' => 'Cash', 'type' => AccountTypeEnum::Asset, 'key' => SystemAccountEnum::Cash],
        ['code' => '1020', 'name' => 'البنك', 'name_en' => 'Bank', 'type' => AccountTypeEnum::Asset, 'key' => SystemAccountEnum::Bank],
        ['code' => '1100', 'name' => 'العملاء (ذمم مدينة)', 'name_en' => 'Accounts Receivable', 'type' => AccountTypeEnum::Asset, 'key' => SystemAccountEnum::AccountsReceivable],
        ['code' => '1200', 'name' => 'ضريبة القيمة المضافة على المشتريات', 'name_en' => 'Input VAT (Recoverable)', 'type' => AccountTypeEnum::Asset, 'key' => SystemAccountEnum::InputVat],
        ['code' => '2010', 'name' => 'ضريبة القيمة المضافة على المبيعات', 'name_en' => 'Output VAT Payable', 'type' => AccountTypeEnum::Liability, 'key' => SystemAccountEnum::VatPayable],
        ['code' => '2020', 'name' => 'إيراد مؤجل (محافظ العملاء)', 'name_en' => 'Deferred Revenue (Wallets)', 'type' => AccountTypeEnum::Liability, 'key' => SystemAccountEnum::DeferredRevenue],
        ['code' => '2100', 'name' => 'الموردون (ذمم دائنة)', 'name_en' => 'Accounts Payable', 'type' => AccountTypeEnum::Liability, 'key' => SystemAccountEnum::AccountsPayable],
        ['code' => '3010', 'name' => 'رأس المال', 'name_en' => 'Capital', 'type' => AccountTypeEnum::Equity, 'key' => SystemAccountEnum::Capital],
        ['code' => '3020', 'name' => 'الأرباح المحتجزة', 'name_en' => 'Retained Earnings', 'type' => AccountTypeEnum::Equity, 'key' => SystemAccountEnum::RetainedEarnings],
        ['code' => '4010', 'name' => 'إيرادات خدمات الغسيل', 'name_en' => 'Laundry Revenue', 'type' => AccountTypeEnum::Revenue, 'key' => SystemAccountEnum::Sales],
        ['code' => '4900', 'name' => 'خصومات المبيعات', 'name_en' => 'Sales Discounts', 'type' => AccountTypeEnum::Revenue, 'key' => SystemAccountEnum::SalesDiscounts],
        ['code' => '5010', 'name' => 'مصروفات تشغيلية', 'name_en' => 'Operating Expenses', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::OperatingExpenses],
        ['code' => '5020', 'name' => 'الرواتب والأجور', 'name_en' => 'Salaries', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::Payroll],
        ['code' => '5030', 'name' => 'الإيجار', 'name_en' => 'Rent', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::Rent],
        ['code' => '5040', 'name' => 'المرافق (كهرباء/ماء)', 'name_en' => 'Utilities', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::Utilities],
        ['code' => '5050', 'name' => 'مستلزمات ومواد', 'name_en' => 'Supplies', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::Supplies],
    ];

    /**
     * Fixed-asset accounts, seeded on first use rather than up front.
     *
     * @var array<int, array{code: string, name: string, name_en: string, type: AccountTypeEnum, key: SystemAccountEnum}>
     */
    private const ASSET_ACCOUNTS = [
        ['code' => '1500', 'name' => 'الأصول الثابتة (معدات وأجهزة)', 'name_en' => 'Fixed Assets', 'type' => AccountTypeEnum::Asset, 'key' => SystemAccountEnum::FixedAsset],
        ['code' => '1590', 'name' => 'مجمع الإهلاك', 'name_en' => 'Accumulated Depreciation', 'type' => AccountTypeEnum::Asset, 'key' => SystemAccountEnum::AccumulatedDepreciation],
        ['code' => '5060', 'name' => 'مصروف الإهلاك', 'name_en' => 'Depreciation Expense', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::DepreciationExpense],
        ['code' => '4950', 'name' => 'أرباح بيع أصول', 'name_en' => 'Gain on Asset Disposal', 'type' => AccountTypeEnum::Revenue, 'key' => SystemAccountEnum::GainOnDisposal],
        ['code' => '5070', 'name' => 'خسائر بيع أصول', 'name_en' => 'Loss on Asset Disposal', 'type' => AccountTypeEnum::Expense, 'key' => SystemAccountEnum::LossOnDisposal],
    ];

    /**
     * Ensure the core chart exists for the organization, seeding only what is missing.
     */
    public function ensureChartOfAccounts(int $organizationId): void
    {
        $this->seed($organizationId, self::CORE_ACCOUNTS);
    }

    /**
     * Resolve a system account, seeding the fixed-asset accounts on demand when one
     * of them is requested for the first time.
     */
    public function systemAccount(int $organizationId, SystemAccountEnum $key): Account
    {
        $account = $this->find($organizationId, $key);

        if ($account !== null) {
            return $account;
        }

        // A missing account is either the very first posting for this organization or
        // the first asset operation. Seed both catalogues and try once more.
        $this->ensureChartOfAccounts($organizationId);
        $this->seed($organizationId, self::ASSET_ACCOUNTS);

        $account = $this->find($organizationId, $key);

        if ($account === null) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, __('api.system_account_missing', ['key' => $key->value]));
        }

        return $account;
    }

    private function find(int $organizationId, SystemAccountEnum $key): ?Account
    {
        return Account::query()
            ->forOrganization($organizationId)
            ->systemKey($key)
            ->first();
    }

    /**
     * @param  array<int, array{code: string, name: string, name_en: string, type: AccountTypeEnum, key: SystemAccountEnum}>  $accounts
     */
    private function seed(int $organizationId, array $accounts): void
    {
        foreach ($accounts as $definition) {
            $exists = Account::query()
                ->forOrganization($organizationId)
                ->systemKey($definition['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            try {
                Account::query()->create([
                    'organization_id' => $organizationId,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'name_en' => $definition['name_en'],
                    'type' => $definition['type']->value,
                    'is_system' => true,
                    'system_key' => $definition['key']->value,
                    'is_active' => true,
                ]);
            } catch (QueryException $exception) {
                // A concurrent seeder inserted the same account first; the unique
                // system-key index rejected the duplicate, which is the desired result.
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }
            }
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
