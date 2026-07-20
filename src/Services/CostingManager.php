<?php
namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\CostingDriver;
use ESolution\Inventory\Enums\ValuationMethod;
use ESolution\Inventory\Models\{Item, DocumentLine};
use ESolution\Inventory\Drivers\Costing\FifoDriver;
use ESolution\Inventory\Drivers\Costing\AverageDriver;

class CostingManager
{
    public function driverFor(DocumentLine $line): CostingDriver
    {
        $item = $line->item()->first();
        $method = $item->valuation ?? inv_cfg('default_valuation','fifo');

        $drivers = inv_cfg('costing_drivers', [
            'fifo'           => FifoDriver::class,
            'average'        => AverageDriver::class,
            'moving_average' => \ESolution\Inventory\Drivers\Costing\MovingAverageDriver::class,
        ]);

        $driverClass = $drivers[$method] ?? $drivers['fifo'];
        return app($driverClass);
    }
}
