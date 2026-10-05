<?php

namespace ESolution\Inventory\Drivers\Costing;

use ESolution\Inventory\Contracts\CostingDriver;
use ESolution\Inventory\DTO\CostingResult;
use ESolution\Inventory\Enums\ValuationMethod;

final class FifoDriver implements CostingDriver
{
    public function method(): ValuationMethod
    {
        return ValuationMethod::FIFO;
    }

    public function issue(array $layers, float $quantity): CostingResult
    {
        $this->requirePositive($quantity);
        $remaining = $quantity;
        $amount = 0.0;
        $allocations = [];

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $taken = min($remaining, $layer['qty']);
            if ($taken <= 0) {
                continue;
            }
            $amount += $taken * $layer['unit_cost'];
            $allocations[] = ['layer_id' => $layer['id'], 'qty' => $taken, 'unit_cost' => $layer['unit_cost']];
            $remaining -= $taken;
        }

        if ($remaining > 0) {
            throw new \DomainException('Insufficient cost layers.');
        }

        return new CostingResult($quantity, $amount / $quantity, $amount, $allocations);
    }

    public function receipt(float $currentQuantity, float $currentValue, float $quantity, float $unitCost): CostingResult
    {
        $this->requirePositive($quantity);

        return new CostingResult($quantity, $unitCost, $quantity * $unitCost);
    }

    private function requirePositive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Costing quantity must be positive.');
        }
    }
}
