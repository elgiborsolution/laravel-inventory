<?php

namespace ESolution\InventoryHealthcare\Services;

use ESolution\Inventory\Models\Item;

final class HealthcarePreset
{
    public function apply(Item $item): Item
    {
        if ($item->item_type !== 'stock') {
            throw new \DomainException('Healthcare preset requires a stock Item.');
        }
        $preset = (array) config('inventory-healthcare.preset', []);
        $item->costing_method = (string) ($preset['costing_method'] ?? 'fefo');
        $item->tracking = array_replace_recursive(
            (array) ($item->tracking ?? []),
            (array) ($preset['tracking'] ?? []),
        );
        $item->save();

        return $item->refresh();
    }
}
