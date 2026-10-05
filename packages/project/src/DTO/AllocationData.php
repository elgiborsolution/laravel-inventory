<?php

namespace ESolution\InventoryProject\DTO;

final class AllocationData
{
    public function __construct(
        public readonly string $allocationNo,
        public readonly string $projectType,
        public readonly string $projectId,
        public readonly int $siteId,
        public readonly int $warehouseId,
        public readonly int $itemId,
        public readonly float $qty,
        public readonly string $kind = 'initial',
        public readonly ?int $sourceAllocationId = null,
        public readonly array $meta = [],
    ) {
        if ($allocationNo === '' || $projectType === '' || $projectId === '' || $qty <= 0) {
            throw new \InvalidArgumentException('Project allocation requires identity, Project reference, and positive quantity.');
        }
        if (! in_array($kind, ['initial', 'replenishment', 'reallocation'], true)) {
            throw new \InvalidArgumentException("Unsupported Project allocation kind '{$kind}'.");
        }
    }
}
