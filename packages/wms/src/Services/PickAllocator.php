<?php

namespace ESolution\InventoryWms\Services;

use ESolution\InventoryWms\DTO\PickingRequest;
use ESolution\InventoryWms\DTO\PickingSuggestion;
use Illuminate\Support\Facades\DB;

final class PickAllocator
{
    /** @return list<PickingSuggestion> */
    public function allocate(PickingRequest $request, string $method): array
    {
        $rows = DB::table('inv_stock_ledgers as ledger')
            ->join('inv_cost_layers as layer', 'layer.id', '=', 'ledger.cost_layer_id')
            ->join('invw_location_profiles as profile', 'profile.storage_location_id', '=', 'ledger.storage_location_id')
            ->leftJoin('inv_batches as batch', 'batch.id', '=', 'layer.batch_id')
            ->where('ledger.item_id', $request->itemId)
            ->where('ledger.warehouse_id', $request->warehouseId)
            ->whereNotNull('ledger.storage_location_id')
            ->where('profile.picking_enabled', true)
            ->where(function ($query): void {
                $query->whereNull('layer.batch_id')
                    ->orWhere(function ($batchQuery): void {
                        $batchQuery->where('batch.status', 'available')
                            ->where(fn($expiryQuery) => $expiryQuery->whereNull('batch.expires_at')
                                ->orWhereDate('batch.expires_at', '>=', now()->toDateString()));
                    });
            })
            ->groupBy(
                'ledger.storage_location_id',
                'layer.batch_id',
                'profile.travel_sequence',
                'batch.expires_at',
            )
            ->havingRaw("SUM(CASE WHEN ledger.direction = 'in' THEN ledger.qty ELSE -ledger.qty END) > 0")
            ->selectRaw("ledger.storage_location_id, layer.batch_id, MIN(layer.received_at) AS received_at, profile.travel_sequence, batch.expires_at, SUM(CASE WHEN ledger.direction = 'in' THEN ledger.qty ELSE -ledger.qty END) AS available_qty")
            ->get();

        $rows = $rows->sort(function (object $left, object $right) use ($method): int {
            $leftExpiry = $left->expires_at ?? '9999-12-31';
            $rightExpiry = $right->expires_at ?? '9999-12-31';
            $leftKey = $method === 'fefo'
                ? [$leftExpiry, $left->received_at, (int) $left->travel_sequence, (int) $left->storage_location_id, (int) $left->batch_id]
                : [$left->received_at, (int) $left->travel_sequence, (int) $left->storage_location_id, (int) $left->batch_id];
            $rightKey = $method === 'fefo'
                ? [$rightExpiry, $right->received_at, (int) $right->travel_sequence, (int) $right->storage_location_id, (int) $right->batch_id]
                : [$right->received_at, (int) $right->travel_sequence, (int) $right->storage_location_id, (int) $right->batch_id];

            return $leftKey <=> $rightKey;
        });

        $remaining = $request->qty;
        $suggestions = [];
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $quantity = min($remaining, (float) $row->available_qty);
            $suggestions[] = new PickingSuggestion(
                (int) $row->storage_location_id,
                $row->batch_id === null ? null : (int) $row->batch_id,
                $quantity,
            );
            $remaining -= $quantity;
        }

        if ($remaining > 0) {
            throw new \DomainException('Insufficient pickable stock for the requested quantity.');
        }

        return $suggestions;
    }
}
