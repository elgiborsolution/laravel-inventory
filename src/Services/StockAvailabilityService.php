<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\DTO\StockAvailability;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\StockLedger;
use Illuminate\Support\Facades\DB;

final class StockAvailabilityService
{
    public function forItem(int $itemId, int $warehouseId): StockAvailability
    {
        $in = (float) StockLedger::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('direction', 'in')
            ->sum('qty');
        $out = (float) StockLedger::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('direction', 'out')
            ->sum('qty');
        $reserved = (float) Reservation::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'active')
            ->selectRaw('COALESCE(SUM(reserved_qty - consumed_qty - released_qty), 0) AS aggregate')
            ->value('aggregate');
        $locked = (float) DB::table('inv_stock_locks')
            ->where('scope_type', 'warehouse')
            ->where('scope_id', $warehouseId)
            ->where(function ($query) use ($itemId): void {
                $query->whereNull('item_id')->orWhere('item_id', $itemId);
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->sum('locked_qty');

        return new StockAvailability($in - $out, $reserved, $locked);
    }
}
