<?php

namespace ESolution\InventoryRetail\Services;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\PolicyOverride;
use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryRetail\Models\ConsignmentTerm;
use Illuminate\Support\Facades\DB;

final class ConsignmentTermsService
{
    public function configure(
        int $itemId,
        string $supplierPartyType,
        string $supplierPartyId,
        ?int $locationId = null,
        ?float $referenceUnitCost = null,
        ?string $periodicity = null,
    ): ConsignmentTerm {
        if (! (bool) config('inventory-retail.consignment.enabled', false)) {
            throw new \DomainException('Retail Consignment is disabled by configuration.');
        }
        Item::query()->findOrFail($itemId);
        if ($locationId !== null) {
            StorageLocation::query()->findOrFail($locationId);
        }
        if ($supplierPartyType === '' || $supplierPartyId === '') {
            throw new \InvalidArgumentException('Consignment supplier type and ID are required.');
        }
        if ($referenceUnitCost !== null && $referenceUnitCost < 0) {
            throw new \InvalidArgumentException('Consignment reference unit cost cannot be negative.');
        }
        $periodicity ??= (string) config('inventory-retail.consignment.settlement.periodicity', 'monthly');
        if (! in_array($periodicity, ['per_sale', 'weekly', 'monthly'], true)) {
            throw new \InvalidArgumentException('Consignment periodicity must be per_sale, weekly, or monthly.');
        }

        return DB::transaction(function () use (
            $itemId,
            $supplierPartyType,
            $supplierPartyId,
            $locationId,
            $referenceUnitCost,
            $periodicity,
        ): ConsignmentTerm {
            $term = ConsignmentTerm::query()->updateOrCreate([
                'item_id' => $itemId,
                'location_scope_key' => $locationId ?? 0,
            ], [
                'location_id' => $locationId,
                'supplier_party_type' => $supplierPartyType,
                'supplier_party_id' => $supplierPartyId,
                'reference_unit_cost' => $referenceUnitCost,
                'settlement_periodicity' => $periodicity,
                'is_active' => true,
            ]);

            PolicyOverride::query()->updateOrCreate([
                'policy_type' => 'inventory_model',
                'item_id' => $itemId,
                'location_id' => $locationId,
            ], [
                'value' => ['model' => 'consignment'],
            ]);

            return $term;
        }, 3);
    }

    public function resolve(int $itemId, ?int $locationId = null): ?ConsignmentTerm
    {
        if ($locationId !== null) {
            $location = ConsignmentTerm::query()
                ->where('item_id', $itemId)
                ->where('location_scope_key', $locationId)
                ->where('is_active', true)
                ->first();
            if ($location !== null) {
                return $location;
            }
        }

        return ConsignmentTerm::query()
            ->where('item_id', $itemId)
            ->where('location_scope_key', 0)
            ->where('is_active', true)
            ->first();
    }
}
