<?php

namespace ESolution\Inventory\Drivers\Costing;

use ESolution\Inventory\Contracts\CostingDriver;
use ESolution\Inventory\DTO\CostingResult;
use ESolution\Inventory\Enums\ValuationMethod;

final class WeightedAverageDriver implements CostingDriver
{
    public function method(): ValuationMethod
    {
        return ValuationMethod::WEIGHTED_AVERAGE;
    }

    public function issue(array $layers, float $quantity): CostingResult
    {
        $available = array_sum(array_column($layers, 'qty'));
        if ($quantity <= 0 || $available < $quantity) {
            throw new \DomainException('Insufficient quantity for weighted-average costing.');
        }
        $value = array_sum(array_map(static fn(array $layer): float => $layer['qty'] * $layer['unit_cost'], $layers));
        $unitCost = $available > 0 ? $value / $available : 0.0;

        return new CostingResult($quantity, $unitCost, $quantity * $unitCost);
    }

    public function receipt(float $currentQuantity, float $currentValue, float $quantity, float $unitCost): CostingResult
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Costing quantity must be positive.');
        }
        $newQuantity = $currentQuantity + $quantity;
        $newValue = $currentValue + ($quantity * $unitCost);
        if ($newQuantity <= 0) {
            throw new \DomainException('Weighted-average receipt must result in positive quantity.');
        }

        return new CostingResult($newQuantity, $newValue / $newQuantity, $newValue);
    }
}
