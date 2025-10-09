<?php
namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\Models\DocumentLine;

interface CostingDriver {
    public function consume(DocumentLine $line, float $qty): float; // return unit cost for OUT
    public function receive(DocumentLine $line, float $qty, float $unitCost): void; // IN
    public function reverse(DocumentLine $line): void; // optional
}
