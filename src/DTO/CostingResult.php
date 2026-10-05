<?php

namespace ESolution\Inventory\DTO;

final class CostingResult
{
    /**
     * @param list<array{layer_id: int, qty: float, unit_cost: float}> $allocations
     */
    public function __construct(
        public readonly float $quantity,
        public readonly float $unitCost,
        public readonly float $amount,
        public readonly array $allocations = [],
    ) {}
}
