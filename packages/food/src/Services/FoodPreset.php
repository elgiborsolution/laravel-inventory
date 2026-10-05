<?php

namespace ESolution\InventoryFood\Services;

use ESolution\Inventory\Models\Item;

final class FoodPreset
{
    public function apply(Item $item): Item
    {
        if ($item->item_type !== 'stock') {
            throw new \DomainException('Food preset requires a stock Item.');
        }

        $existing = (array) ($item->tracking ?? []);
        $preset = (array) config('inventory-food.preset.tracking', []);
        $certificates = array_values(array_unique(array_merge(
            (array) ($existing['required_batch_certificates_on_issue'] ?? []),
            (array) ($preset['required_batch_certificates_on_issue'] ?? []),
        )));
        $item->tracking = array_replace_recursive($existing, $preset);
        $item->tracking = array_replace($item->tracking, [
            'required_batch_certificates_on_issue' => $certificates,
        ]);
        $item->save();

        return $item->refresh();
    }
}
