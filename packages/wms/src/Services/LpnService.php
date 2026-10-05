<?php

namespace ESolution\InventoryWms\Services;

use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Models\Lpn;
use ESolution\InventoryWms\Models\LpnContent;
use Illuminate\Support\Facades\DB;

final class LpnService
{
    public function create(string $code, int $warehouseId, ?int $locationId = null): Lpn
    {
        $this->assertLocation($warehouseId, $locationId);

        $lpn = Lpn::query()->firstOrCreate(['code' => $code], [
            'warehouse_id' => $warehouseId,
            'storage_location_id' => $locationId,
        ]);
        if ((int) $lpn->warehouse_id !== $warehouseId
            || ($locationId !== null && (int) $lpn->storage_location_id !== $locationId)) {
            throw new \DomainException('LPN code is already assigned to a different warehouse or location.');
        }

        return $lpn;
    }

    public function add(int $lpnId, int $itemId, float $qty, ?int $batchId = null): LpnContent
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('LPN quantity must be positive.');
        }
        if ($batchId !== null && ! Batch::query()->whereKey($batchId)->where('item_id', $itemId)->exists()) {
            throw new \DomainException('LPN batch belongs to a different item.');
        }

        return DB::transaction(function () use ($lpnId, $itemId, $qty, $batchId): LpnContent {
            Lpn::query()->lockForUpdate()->findOrFail($lpnId);
            $content = LpnContent::query()->firstOrNew([
                'lpn_id' => $lpnId,
                'item_id' => $itemId,
                'batch_scope_key' => $batchId ?? 0,
            ]);
            $content->batch_id = $batchId;
            $content->qty = (float) ($content->qty ?? 0) + $qty;
            $content->save();

            return $content;
        });
    }

    public function remove(int $lpnId, int $itemId, float $qty, ?int $batchId = null): ?LpnContent
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('LPN quantity must be positive.');
        }

        return DB::transaction(function () use ($lpnId, $itemId, $qty, $batchId): ?LpnContent {
            $content = LpnContent::query()
                ->where('lpn_id', $lpnId)
                ->where('item_id', $itemId)
                ->where('batch_scope_key', $batchId ?? 0)
                ->lockForUpdate()
                ->firstOrFail();
            if ((float) $content->qty < $qty) {
                throw new \DomainException('LPN content cannot become negative.');
            }
            $content->qty = (float) $content->qty - $qty;
            if ((float) $content->qty === 0.0) {
                $content->delete();

                return null;
            }
            $content->save();

            return $content;
        });
    }

    public function relocate(int $lpnId, int $locationId): Lpn
    {
        return DB::transaction(function () use ($lpnId, $locationId): Lpn {
            $lpn = Lpn::query()->lockForUpdate()->findOrFail($lpnId);
            $this->assertLocation((int) $lpn->warehouse_id, $locationId);
            $lpn->storage_location_id = $locationId;
            $lpn->save();

            return $lpn;
        });
    }

    private function assertLocation(int $warehouseId, ?int $locationId): void
    {
        if ($locationId !== null && ! StorageLocation::query()
            ->whereKey($locationId)
            ->where('organization_id', $warehouseId)
            ->where('is_active', true)
            ->exists()) {
            throw new \DomainException('LPN location must be active and belong to its warehouse.');
        }
    }
}
