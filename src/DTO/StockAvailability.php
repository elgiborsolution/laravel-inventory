<?php

namespace ESolution\Inventory\DTO;

final class StockAvailability
{
    public readonly float $availableQty;

    public function __construct(
        public readonly float $onHandQty,
        public readonly float $reservedQty,
        public readonly float $lockedQty,
    ) {
        $this->availableQty = $onHandQty - $reservedQty - $lockedQty;
    }

    public function availableQty(): float
    {
        return $this->availableQty;
    }
}
