<?php

namespace ESolution\InventoryAsset\Console\Commands;

use ESolution\InventoryAsset\Services\OverdueService;
use Illuminate\Console\Command;

final class DetectOverdueAssetsCommand extends Command
{
    protected $signature = 'inventory-assets:detect-overdue';

    protected $description = 'Notify about derived overdue Asset checkouts without changing checkout or stock state';

    public function handle(OverdueService $overdue): int
    {
        $checkouts = $overdue->detect();
        $this->info("Detected {$checkouts->count()} overdue Asset checkout(s).");

        return self::SUCCESS;
    }
}
