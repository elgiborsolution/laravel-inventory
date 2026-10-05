<?php

namespace ESolution\InventoryWms\DTO;

final class PickingRequest
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $warehouseId,
        public readonly float $qty,
    ) {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Picking quantity must be positive.');
        }
    }
}
