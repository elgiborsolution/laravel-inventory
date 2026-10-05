<?php

namespace ESolution\Inventory\Drivers\Costing;

use ESolution\Inventory\Contracts\CostingDriver;
use ESolution\Inventory\DTO\CostingResult;
use ESolution\Inventory\Enums\ValuationMethod;

final class MovingAverageDriver implements CostingDriver
{
    public function method(): ValuationMethod
    {
        return ValuationMethod::MOVING_AVERAGE;
    }

    public function issue(array $layers, float $quantity): CostingResult
    {
        if ($quantity <= 0 || $layers === []) {
            throw new \DomainException('Moving-average issue requires an active average layer.');
        }
        $layer = $layers[array_key_last($layers)];
        if ($layer['qty'] < $quantity) {
            throw new \DomainException('Insufficient quantity for moving-average costing.');
        }

        return new CostingResult($quantity, $layer['unit_cost'], $quantity * $layer['unit_cost']);
    }

    public function receipt(float $currentQuantity, float $currentValue, float $quantity, float $unitCost): CostingResult
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Costing quantity must be positive.');
        }
        $newQuantity = $currentQuantity + $quantity;
        $newValue = $currentValue + ($quantity * $unitCost);
        if ($newQuantity <= 0) {
            throw new \DomainException('Moving-average receipt must result in positive quantity.');
        }

        return new CostingResult($newQuantity, $newValue / $newQuantity, $newValue);
    }
}
