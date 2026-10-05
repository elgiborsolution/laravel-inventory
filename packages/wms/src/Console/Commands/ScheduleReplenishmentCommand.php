<?php

namespace ESolution\InventoryWms\Console\Commands;

use ESolution\InventoryWms\Services\ReplenishmentScheduler;
use Illuminate\Console\Command;

final class ScheduleReplenishmentCommand extends Command
{
    protected $signature = 'inventory-wms:replenish {--warehouse=}';

    protected $description = 'Create idempotent internal replenishment work without posting inventory.';

    public function handle(ReplenishmentScheduler $scheduler): int
    {
        $warehouse = $this->option('warehouse');
        $tasks = $scheduler->schedule($warehouse === null ? null : (int) $warehouse);
        $this->info(sprintf('Prepared %d replenishment task(s).', count($tasks)));

        return self::SUCCESS;
    }
}
