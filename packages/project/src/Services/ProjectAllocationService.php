<?php

namespace ESolution\InventoryProject\Services;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReservationConsumptionData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Organization;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryProject\DTO\AllocationData;
use ESolution\InventoryProject\Models\ProjectAllocation;
use ESolution\InventoryProject\Models\ProjectReallocation;
use Illuminate\Support\Facades\DB;

final class ProjectAllocationService
{
    public function __construct(private readonly InventoryManager $inventory) {}

    public function allocate(AllocationData $data): ProjectAllocation
    {
        return DB::transaction(fn(): ProjectAllocation => $this->createAllocation($data), 3);
    }

    public function replenish(int $sourceAllocationId, string $allocationNo, float $qty, array $meta = []): ProjectAllocation
    {
        return DB::transaction(function () use ($sourceAllocationId, $allocationNo, $qty, $meta): ProjectAllocation {
            $source = ProjectAllocation::query()->lockForUpdate()->findOrFail($sourceAllocationId);

            return $this->createAllocation(new AllocationData(
                allocationNo: $allocationNo,
                projectType: $source->project_type,
                projectId: $source->project_id,
                siteId: (int) $source->site_id,
                warehouseId: (int) $source->warehouse_id,
                itemId: (int) $source->item_id,
                qty: $qty,
                kind: 'replenishment',
                sourceAllocationId: (int) $source->getKey(),
                meta: $meta,
            ));
        }, 3);
    }

    public function reallocate(
        string $reallocationNo,
        int $sourceAllocationId,
        string $destinationAllocationNo,
        int $destinationSiteId,
        int $destinationWarehouseId,
        float $qty,
        array $meta = [],
    ): ProjectReallocation {
        if ($reallocationNo === '' || $qty <= 0) {
            throw new \InvalidArgumentException('Project reallocation requires identity and positive quantity.');
        }

        return DB::transaction(function () use (
            $reallocationNo,
            $sourceAllocationId,
            $destinationAllocationNo,
            $destinationSiteId,
            $destinationWarehouseId,
            $qty,
            $meta,
        ): ProjectReallocation {
            $source = ProjectAllocation::query()->lockForUpdate()->findOrFail($sourceAllocationId);
            $existing = ProjectReallocation::query()
                ->where('reallocation_no', $reallocationNo)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $destination = ProjectAllocation::query()->findOrFail($existing->destination_allocation_id);
                if ((int) $existing->source_allocation_id !== $sourceAllocationId
                    || (float) $existing->qty !== $qty
                    || $destination->allocation_no !== $destinationAllocationNo
                    || (int) $destination->site_id !== $destinationSiteId
                    || (int) $destination->warehouse_id !== $destinationWarehouseId) {
                    throw new \DomainException('Project reallocation number was reused with a different payload.');
                }

                return $existing->load('sourceAllocation.reservation', 'destinationAllocation.reservation');
            }

            $sourceReservation = Reservation::query()->lockForUpdate()->findOrFail($source->reservation_id);
            if ($source->status !== 'active' || $qty > $sourceReservation->remaining_qty) {
                throw new \DomainException('Project reallocation exceeds the active source allocation balance.');
            }

            $this->inventory->release((int) $sourceReservation->getKey(), $qty);
            $destination = $this->createAllocation(new AllocationData(
                allocationNo: $destinationAllocationNo,
                projectType: $source->project_type,
                projectId: $source->project_id,
                siteId: $destinationSiteId,
                warehouseId: $destinationWarehouseId,
                itemId: (int) $source->item_id,
                qty: $qty,
                kind: 'reallocation',
                sourceAllocationId: (int) $source->getKey(),
                meta: $meta,
            ));
            $source->status = $sourceReservation->refresh()->remaining_qty <= 0 ? 'reallocated' : 'active';
            $source->save();

            return ProjectReallocation::query()->create([
                'reallocation_no' => $reallocationNo,
                'source_allocation_id' => $source->getKey(),
                'destination_allocation_id' => $destination->getKey(),
                'qty' => $qty,
            ])->load('sourceAllocation.reservation', 'destinationAllocation.reservation');
        }, 3);
    }

    public function draw(
        int $allocationId,
        float $qty,
        string $externalId,
        ?string $trxDate = null,
        ?int $storageLocationId = null,
        ?int $batchId = null,
        ?int $serialId = null,
    ): Document {
        if ($qty <= 0 || $externalId === '') {
            throw new \InvalidArgumentException('Project material draw requires positive quantity and an external ID.');
        }

        return DB::transaction(function () use (
            $allocationId,
            $qty,
            $externalId,
            $trxDate,
            $storageLocationId,
            $batchId,
            $serialId,
        ): Document {
            $allocation = ProjectAllocation::query()->lockForUpdate()->findOrFail($allocationId);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($allocation->reservation_id);
            $item = Item::query()->findOrFail($allocation->item_id);
            $document = $this->inventory->post(new DocumentData(
                type: 'goods_issue',
                organizationId: (int) $allocation->site_id,
                trxDate: $trxDate ?? now()->toDateString(),
                externalId: $externalId,
                sourceType: ProjectAllocation::class,
                sourceId: $allocation->allocation_no,
                lines: [new LineData(
                    (int) $item->getKey(),
                    (int) $item->base_uom_id,
                    (int) $allocation->warehouse_id,
                    $qty,
                    $storageLocationId,
                    batchId: $batchId,
                    serialId: $serialId,
                    meta: ['project_allocation_id' => $allocation->getKey()],
                )],
                meta: [
                    'project_allocation_id' => $allocation->getKey(),
                    'project_type' => $allocation->project_type,
                    'project_id' => $allocation->project_id,
                    'site_id' => $allocation->site_id,
                ],
                reservationConsumptions: [new ReservationConsumptionData(
                    (int) $reservation->getKey(),
                    1,
                    $qty,
                    'project-draw:' . $externalId,
                )],
            ));

            $allocation->status = $reservation->refresh()->remaining_qty <= 0 ? 'consumed' : 'active';
            $allocation->save();

            return $document;
        }, 3);
    }

    private function createAllocation(AllocationData $data): ProjectAllocation
    {
        $item = Item::query()->lockForUpdate()->findOrFail($data->itemId);
        if (! $item->is_active || $item->item_type !== 'stock') {
            throw new \DomainException('Project allocation requires an active stock Item.');
        }
        $this->assertSiteWarehouse($data->siteId, $data->warehouseId);

        $existing = ProjectAllocation::query()
            ->where('allocation_no', $data->allocationNo)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return $this->assertSamePayload($existing, $data);
        }
        if ($this->inventory->availability($data->itemId, $data->warehouseId)->availableQty() < $data->qty) {
            throw new \DomainException('Insufficient available stock for Project allocation.');
        }

        $reservation = $this->inventory->reserve(
            $data->itemId,
            $data->qty,
            $data->warehouseId,
            ProjectAllocation::class,
            $data->allocationNo,
        );

        return ProjectAllocation::query()->create([
            'allocation_no' => $data->allocationNo,
            'project_type' => $data->projectType,
            'project_id' => $data->projectId,
            'site_id' => $data->siteId,
            'warehouse_id' => $data->warehouseId,
            'item_id' => $data->itemId,
            'reservation_id' => $reservation->getKey(),
            'source_allocation_id' => $data->sourceAllocationId,
            'kind' => $data->kind,
            'status' => 'active',
            'meta' => $data->meta,
        ])->load('site', 'warehouse', 'item', 'reservation');
    }

    private function assertSiteWarehouse(int $siteId, int $warehouseId): void
    {
        $site = Organization::query()->findOrFail($siteId);
        $warehouse = Organization::query()->findOrFail($warehouseId);
        if (! $site->is_active || ! $warehouse->is_active || $warehouse->type !== 'warehouse') {
            throw new \DomainException('Project allocation requires active Core Site and warehouse organizations.');
        }
        if ($siteId === $warehouseId) {
            return;
        }

        $ancestorId = $warehouse->parent_id === null ? null : (int) $warehouse->parent_id;
        $visited = [];
        while ($ancestorId !== null && ! isset($visited[$ancestorId])) {
            if ($ancestorId === $siteId) {
                return;
            }
            $visited[$ancestorId] = true;
            $ancestor = Organization::query()->find($ancestorId);
            $ancestorId = $ancestor?->parent_id === null ? null : (int) $ancestor->parent_id;
        }

        throw new \DomainException('Project warehouse must be the Site itself or its Core organization descendant.');
    }

    private function assertSamePayload(ProjectAllocation $allocation, AllocationData $data): ProjectAllocation
    {
        $reservation = Reservation::query()->findOrFail($allocation->reservation_id);
        $same = $allocation->project_type === $data->projectType
            && $allocation->project_id === $data->projectId
            && (int) $allocation->site_id === $data->siteId
            && (int) $allocation->warehouse_id === $data->warehouseId
            && (int) $allocation->item_id === $data->itemId
            && (float) $reservation->reserved_qty === $data->qty
            && $allocation->kind === $data->kind
            && ($allocation->source_allocation_id === null ? null : (int) $allocation->source_allocation_id) === $data->sourceAllocationId;
        if (! $same) {
            throw new \DomainException('Project allocation number was reused with a different payload.');
        }

        return $allocation->load('site', 'warehouse', 'item', 'reservation');
    }
}
