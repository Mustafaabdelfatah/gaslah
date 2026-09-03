<?php

namespace App\Console\Commands;

use App\Models\RecurringBill;
use App\Services\Accounting\PayablesService;
use Illuminate\Console\Command;

/**
 * Materializes every recurring bill/expense occurrence due through today.
 */
class PayablesRunDue extends Command
{
    protected $signature = 'payables:run-due';

    protected $description = 'Generate due recurring supplier bills and expenses';

    public function handle(PayablesService $payables): int
    {
        $organizations = RecurringBill::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('organization_id');

        $generated = 0;

        foreach ($organizations as $organizationId) {
            $generated += $payables->runDue((int) $organizationId);
        }

        $this->info("Payables: {$generated} recurring occurrences generated.");

        return self::SUCCESS;
    }
}
