<?php

namespace ESolution\InventoryWms\DTO;

final class PutAwayRequest
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $warehouseId,
        public readonly float $qty,
        public readonly ?int $fromLocationId = null,
        public readonly ?string $zone = null,
        public readonly string $deterministicKey = '',
    ) {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Put-away quantity must be positive.');
        }
    }
}
