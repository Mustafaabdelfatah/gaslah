<?php

namespace App\Console\Commands;

use App\Services\Orders\AutomationSweeper;
use Illuminate\Console\Command;

/**
 * Advances aged orders toward READY. Scheduled every five minutes.
 */
class AutomationSweep extends Command
{
    protected $signature = 'automation:sweep';

    protected $description = 'Auto-advance aged orders to ready across all organizations';

    public function handle(AutomationSweeper $sweeper): int
    {
        $result = $sweeper->sweep();

        $this->info("Automation sweep: {$result['orgs']} orgs, {$result['scanned']} scanned, {$result['advanced']} advanced.");

        return self::SUCCESS;
    }
}
