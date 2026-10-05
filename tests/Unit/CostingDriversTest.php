<?php

use ESolution\Inventory\Drivers\Costing\FifoDriver;
use ESolution\Inventory\Drivers\Costing\MovingAverageDriver;
use ESolution\Inventory\Drivers\Costing\WeightedAverageDriver;
use ESolution\Inventory\Enums\ValuationMethod;

it('calculates FIFO allocations in supplied layer order', function (): void {
    $result = (new FifoDriver())->issue([
        ['id' => 10, 'qty' => 2.0, 'unit_cost' => 5.0],
        ['id' => 11, 'qty' => 5.0, 'unit_cost' => 8.0],
    ], 4.0);

    expect($result->amount)->toBe(26.0)
        ->and($result->unitCost)->toBe(6.5)
        ->and($result->allocations)->toHaveCount(2);
});

it('keeps weighted average and moving average as separate drivers', function (): void {
    $weighted = new WeightedAverageDriver();
    $moving = new MovingAverageDriver();

    $weightedReceipt = $weighted->receipt(10, 50, 10, 15);
    $movingReceipt = $moving->receipt(10, 50, 10, 15);

    expect($weighted->method())->toBe(ValuationMethod::WEIGHTED_AVERAGE)
        ->and($moving->method())->toBe(ValuationMethod::MOVING_AVERAGE)
        ->and($weightedReceipt->unitCost)->toBe(10.0)
        ->and($movingReceipt->unitCost)->toBe(10.0);
});
