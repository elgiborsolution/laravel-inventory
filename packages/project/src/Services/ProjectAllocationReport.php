<?php

namespace ESolution\InventoryProject\Services;

use ESolution\Inventory\Models\Reservation;
use ESolution\InventoryProject\DTO\AllocationTotals;
use ESolution\InventoryProject\Models\ProjectAllocation;

final class ProjectAllocationReport
{
    public function totals(
        string $projectType,
        string $projectId,
        ?int $siteId = null,
        ?int $itemId = null,
    ): AllocationTotals {
        $allocations = ProjectAllocation::query()
            ->where('project_type', $projectType)
            ->where('project_id', $projectId)
            ->when($siteId !== null, fn($query) => $query->where('site_id', $siteId))
            ->when($itemId !== null, fn($query) => $query->where('item_id', $itemId))
            ->get();

        $allocated = 0.0;
        $consumed = 0.0;
        $released = 0.0;
        foreach ($allocations as $allocation) {
            $reservation = Reservation::query()
                ->with('consumptions')
                ->findOrFail($allocation->reservation_id);
            $allocated += (float) $reservation->reserved_qty;
            $consumed += (float) $reservation->consumptions->sum('qty');
            $released += (float) $reservation->released_qty;
        }

        return new AllocationTotals(
            allocatedQty: $allocated,
            consumedQty: $consumed,
            releasedQty: $released,
            remainingQty: $allocated - $consumed - $released,
            allocationCount: $allocations->count(),
        );
    }
}
