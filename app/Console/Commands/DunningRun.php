<?php

namespace App\Console\Commands;

use App\Services\Platform\DunningService;
use Illuminate\Console\Command;

/**
 * Runs one subscription dunning cycle. Scheduled daily; a no-op while the policy is
 * disabled.
 */
class DunningRun extends Command
{
    protected $signature = 'platform:dunning';

    protected $description = 'Run the subscription dunning cycle (reminders, renewals, suspensions)';

    public function handle(DunningService $dunning): int
    {
        $summary = $dunning->run();

        $this->info(sprintf(
            'Dunning: %d processed, %d reminders, %d invoices, %d lapsed, %d suspended.',
            $summary['processed'],
            $summary['reminders'],
            $summary['invoices'],
            $summary['lapsed'],
            $summary['suspended'],
        ));

        return self::SUCCESS;
    }
}
