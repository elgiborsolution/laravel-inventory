<?php

namespace ESolution\InventoryAsset\Services;

use ESolution\Inventory\Models\Item;

final class AssetPreset
{
    public function apply(Item $item): Item
    {
        $allowed = (array) config('inventory-asset.allowed_item_types', ['stock']);
        if (! $item->is_active || ! in_array($item->item_type, $allowed, true)) {
            throw new \DomainException("Asset preset does not allow Item Type '{$item->item_type}' or an inactive Item.");
        }

        $item->tracking = array_replace_recursive(
            (array) ($item->tracking ?? []),
            (array) config('inventory-asset.preset.tracking', []),
        );
        $item->save();

        return $item->refresh();
    }
}
