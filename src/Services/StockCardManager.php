<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Models\CostAdjustment;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\StockCard;
use ESolution\Inventory\Models\StockLedger;

final class StockCardManager
{
    public function __construct(private readonly ConfigurationDepthResolver $depth) {}

    public function refresh(DocumentLine $line): StockCard
    {
        [$type, $id] = $this->depth->costingScope(
            (int) $line->warehouse_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        );
        $entries = StockLedger::query()
            ->where('item_id', $line->item_id)
            ->when(
                $type === 'warehouse',
                fn($query) => $query->where('warehouse_id', $id),
                fn($query) => $query->where('storage_location_id', $id),
            )
            ->get();
        $quantity = 0.0;
        $value = 0.0;
        foreach ($entries as $entry) {
            $sign = $entry->direction === 'in' ? 1 : -1;
            $quantity += $sign * (float) $entry->qty;
            $value += $sign * (float) $entry->amount;
        }

        $value -= (float) CostAdjustment::query()
            ->where('item_id', $line->item_id)
            ->where('scope_type', $type)
            ->where('scope_id', $id)
            ->sum('amount_delta');

        return StockCard::updateOrCreate([
            'item_id' => $line->item_id,
            'scope_type' => $type,
            'scope_id' => $id,
            'as_of' => now()->startOfDay(),
        ], [
            'running_qty' => $quantity,
            'running_value' => $value,
            'avg_cost' => $quantity > 0 ? $value / $quantity : 0,
        ]);
    }
}
