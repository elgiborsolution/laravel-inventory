<?php

namespace ESolution\InventoryManufacturing\Services;

use ESolution\Inventory\Models\Item;
use ESolution\InventoryManufacturing\DTO\BomComponentData;
use ESolution\InventoryManufacturing\Models\Bom;
use ESolution\InventoryManufacturing\Models\BomComponent;
use ESolution\InventoryManufacturing\Models\BomVersion;
use Illuminate\Support\Facades\DB;

final class BomService
{
    public function create(string $code, string $name, int $outputItemId): Bom
    {
        if ($code === '' || $name === '') {
            throw new \InvalidArgumentException('BOM requires a code and name.');
        }
        $this->assertItemType($outputItemId, 'allowed_output_item_types', 'output');

        return Bom::query()->create([
            'code' => $code,
            'name' => $name,
            'output_item_id' => $outputItemId,
        ]);
    }

    /** @param list<BomComponentData> $components */
    public function createVersion(
        int $bomId,
        float $outputQty,
        array $components,
        ?string $effectiveFrom = null,
        ?string $effectiveTo = null,
    ): BomVersion {
        if ($outputQty <= 0 || $components === []) {
            throw new \InvalidArgumentException('A BOM version requires positive output and at least one component.');
        }

        return DB::transaction(function () use ($bomId, $outputQty, $components, $effectiveFrom, $effectiveTo): BomVersion {
            $bom = Bom::query()->lockForUpdate()->findOrFail($bomId);
            $seen = [];
            foreach ($components as $component) {
                if (! $component instanceof BomComponentData) {
                    throw new \InvalidArgumentException('BOM components must use BomComponentData.');
                }
                if ($component->itemId === (int) $bom->output_item_id) {
                    throw new \DomainException('A BOM cannot directly consume its own output item.');
                }
                if (isset($seen[$component->itemId])) {
                    throw new \DomainException('A BOM version cannot contain the same component item twice.');
                }
                $seen[$component->itemId] = true;
                $this->assertItemType($component->itemId, 'allowed_component_item_types', 'component');
            }

            $version = BomVersion::query()->create([
                'bom_id' => $bom->getKey(),
                'version' => (int) $bom->versions()->max('version') + 1,
                'output_qty' => $outputQty,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);
            foreach ($components as $component) {
                BomComponent::query()->create([
                    'bom_version_id' => $version->getKey(),
                    'item_id' => $component->itemId,
                    'uom_id' => $component->uomId,
                    'qty' => $component->qty,
                    'sequence' => $component->sequence,
                ]);
            }

            return $version->load('bom', 'components');
        });
    }

    public function activate(int $versionId): BomVersion
    {
        return DB::transaction(function () use ($versionId): BomVersion {
            $version = BomVersion::query()->lockForUpdate()->findOrFail($versionId);
            if ($version->status === 'active') {
                return $version->load('bom', 'components');
            }
            if ($version->status !== 'draft' || ! $version->components()->exists()) {
                throw new \DomainException('Only a populated draft BOM version can be activated.');
            }
            $this->assertVersionItems($version->load('bom', 'components'));
            $version->status = 'active';
            $version->activated_at = now();
            $version->save();

            return $version->load('bom', 'components');
        });
    }

    public function assertVersionItems(BomVersion $version): void
    {
        $bom = Bom::query()->findOrFail($version->bom_id);
        $this->assertItemType((int) $bom->output_item_id, 'allowed_output_item_types', 'output');
        foreach (BomComponent::query()->where('bom_version_id', $version->getKey())->get() as $component) {
            $this->assertItemType((int) $component->item_id, 'allowed_component_item_types', 'component');
        }
    }

    private function assertItemType(int $itemId, string $configKey, string $role): Item
    {
        $item = Item::query()->findOrFail($itemId);
        $allowed = (array) config("inventory-manufacturing.{$configKey}", ['stock']);
        if (! $item->is_active || ! in_array($item->item_type, $allowed, true)) {
            throw new \DomainException("Manufacturing {$role} Item Type '{$item->item_type}' is not allowed or the item is inactive.");
        }

        return $item;
    }
}
