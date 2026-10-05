<?php

namespace ESolution\InventoryRetail\DTO;

final class ConsignmentPosition
{
    public function __construct(
        public readonly float $physicalQty,
        public readonly float $referenceValue,
        public readonly float $ownedValue = 0.0,
    ) {}
}
