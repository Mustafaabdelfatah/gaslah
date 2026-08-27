<?php

namespace App\Enum\Accounting;

/**
 * Stable keys binding automatic posting logic to specific chart-of-accounts rows.
 *
 * The posting engine never hardcodes an account code; it resolves the account by its
 * system key, so an organization can rename an account without breaking the wiring.
 * These keys are protected: a system account cannot have its code, type or key changed.
 */
enum SystemAccountEnum: string
{
    // Core chart (seeded for every organization)
    case Cash = 'cash';
    case Bank = 'bank';
    case AccountsReceivable = 'ar';
    case InputVat = 'input_vat';
    case VatPayable = 'vat_payable';
    case DeferredRevenue = 'deferred_revenue';
    case AccountsPayable = 'ap';
    case Capital = 'capital';
    case RetainedEarnings = 'retained';
    case Sales = 'sales';
    case SalesDiscounts = 'sales_discounts';

    /**
     * Platform-books only: hardware the operator sells sits apart from subscription
     * revenue, so the SaaS income statement can tell recurring from one-off.
     */
    case DeviceSales = 'device_sales';
    case OperatingExpenses = 'opex';
    case Payroll = 'payroll';
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Supplies = 'supplies';

    // Fixed-asset accounts (seeded lazily on first asset operation)
    case FixedAsset = 'fixed_asset';
    case AccumulatedDepreciation = 'accum_dep';
    case DepreciationExpense = 'dep_expense';
    case GainOnDisposal = 'gain_disposal';
    case LossOnDisposal = 'loss_disposal';
}
