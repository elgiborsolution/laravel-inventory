<?php

namespace ESolution\InventoryProject\DTO;

final class AllocationTotals
{
    public function __construct(
        public readonly float $allocatedQty,
        public readonly float $consumedQty,
        public readonly float $releasedQty,
        public readonly float $remainingQty,
        public readonly int $allocationCount,
    ) {}
}
