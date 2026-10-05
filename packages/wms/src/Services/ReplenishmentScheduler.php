<?php

namespace ESolution\InventoryWms\Services;

use ESolution\InventoryWms\Models\ReplenishmentRule;
use ESolution\InventoryWms\Models\Task;

final class ReplenishmentScheduler
{
    public function __construct(private readonly LocationInventory $inventory) {}

    /** @return list<Task> */
    public function schedule(?int $warehouseId = null): array
    {
        $rules = ReplenishmentRule::query()
            ->where('is_active', true)
            ->when($warehouseId !== null, fn($query) => $query->where('warehouse_id', $warehouseId))
            ->orderBy('id')
            ->get();
        $created = [];
        foreach ($rules as $rule) {
            $pending = Task::query()
                ->where('type', 'replenishment')
                ->whereIn('status', ['open', 'in_progress'])
                ->where('meta->replenishment_rule_id', $rule->getKey())
                ->orderBy('id')
                ->first();
            if ($pending !== null) {
                $created[] = $pending;

                continue;
            }
            $pickQty = $this->inventory->quantity((int) $rule->pick_location_id, (int) $rule->item_id);
            if ($pickQty >= (float) $rule->minimum_qty) {
                continue;
            }
            $sourceQty = $this->inventory->quantity((int) $rule->source_location_id, (int) $rule->item_id);
            $qty = min((float) $rule->target_qty - $pickQty, $sourceQty);
            if ($qty <= 0) {
                continue;
            }
            $key = sprintf('replenishment:%d:pick:%0.6F:source:%0.6F', $rule->getKey(), $pickQty, $sourceQty);
            $created[] = Task::query()->firstOrCreate(['idempotency_key' => $key], [
                'type' => 'replenishment',
                'warehouse_id' => $rule->warehouse_id,
                'item_id' => $rule->item_id,
                'qty' => $qty,
                'from_location_id' => $rule->source_location_id,
                'to_location_id' => $rule->pick_location_id,
                'meta' => ['replenishment_rule_id' => $rule->getKey()],
            ]);
        }

        return $created;
    }
}
