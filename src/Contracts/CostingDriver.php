<?php

namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\DTO\CostingResult;
use ESolution\Inventory\Enums\ValuationMethod;

interface CostingDriver
{
    public function method(): ValuationMethod;

    /** @param list<array{id: int, qty: float, unit_cost: float}> $layers */
    public function issue(array $layers, float $quantity): CostingResult;

    public function receipt(float $currentQuantity, float $currentValue, float $quantity, float $unitCost): CostingResult;
}
