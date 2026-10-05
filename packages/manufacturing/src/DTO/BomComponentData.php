<?php

namespace ESolution\InventoryManufacturing\DTO;

final class BomComponentData
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $uomId,
        public readonly float $qty,
        public readonly int $sequence = 0,
    ) {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('BOM component quantity must be positive.');
        }
    }
}
