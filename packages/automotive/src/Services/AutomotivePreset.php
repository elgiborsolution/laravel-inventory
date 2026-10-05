<?php

namespace ESolution\InventoryAutomotive\Services;

use ESolution\Inventory\Models\Item;

final class AutomotivePreset
{
    public function apply(Item $item): Item
    {
        if ($item->item_type !== 'stock') {
            throw new \DomainException('Automotive preset requires a stock Item.');
        }
        $existing = (array) ($item->tracking ?? []);
        $preset = (array) config('inventory-automotive.preset.tracking', []);
        $certificates = array_values(array_unique(array_merge(
            (array) ($existing['required_serial_certificates_on_issue'] ?? []),
            (array) ($preset['required_serial_certificates_on_issue'] ?? []),
        )));
        $item->tracking = array_replace($existing, $preset, ['required_serial_certificates_on_issue' => $certificates]);
        $item->save();
        return $item->refresh();
    }
}
