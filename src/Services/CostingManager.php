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
        return match($method){
            'average' => app(AverageDriver::class),
            default   => app(FifoDriver::class),
        };
    }
}
