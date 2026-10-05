<?php

namespace ESolution\InventoryRetail\Console\Commands;

use ESolution\InventoryRetail\Models\ConsignmentSettlement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SettleConsignmentCommand extends Command
{
    protected $signature = 'inventory-retail:consignment:settle
        {--through= : Include pending obligations through this YYYY-MM-DD date}
        {--supplier-type= : Limit to one supplier morph type}
        {--supplier-id= : Limit to one supplier ID}';

    protected $description = 'Mark selected Consignment obligations settled without posting accounting entries';

    public function handle(): int
    {
        $through = $this->option('through') ?: now()->toDateString();
        $supplierType = $this->option('supplier-type');
        $supplierId = $this->option('supplier-id');

        $count = DB::transaction(function () use ($through, $supplierType, $supplierId): int {
            $query = ConsignmentSettlement::query()
                ->where('status', 'pending')
                ->whereDate('sale_date', '<=', $through)
                ->when($supplierType, fn($builder) => $builder->where('supplier_party_type', $supplierType))
                ->when($supplierId, fn($builder) => $builder->where('supplier_party_id', $supplierId))
                ->lockForUpdate();

            return $query->update(['status' => 'settled', 'settled_at' => now(), 'updated_at' => now()]);
        }, 3);

        $this->info("Marked {$count} Consignment obligation(s) as settled. No accounting entry was posted.");

        return self::SUCCESS;
    }
}
