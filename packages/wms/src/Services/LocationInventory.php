<?php

namespace ESolution\InventoryWms\Services;

use Illuminate\Support\Facades\DB;

final class LocationInventory
{
    public function quantity(int $locationId, ?int $itemId = null): float
    {
        $query = DB::table('inv_stock_ledgers')->where('storage_location_id', $locationId);
        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }

        return (float) $query
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN qty ELSE -qty END), 0) AS stock_qty")
            ->value('stock_qty');
    }
}
