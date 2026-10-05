<?php

namespace ESolution\InventoryLibrary\Services;

use ESolution\Inventory\Models\Item;

final class LibraryPreset
{
    public function apply(Item $item): Item
    {
        if (! $item->is_active || $item->item_type !== 'stock') {
            throw new \DomainException('Library requires an active stock Item.');
        }
        $item->tracking = array_replace((array) $item->tracking, (array) config('inventory-library.preset.tracking', []));
        $item->save();
        return $item->refresh();
    }
}
