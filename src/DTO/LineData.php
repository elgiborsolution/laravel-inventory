<?php

namespace ESolution\Inventory\DTO;

final class LineData
{
    public function __construct(public int $itemId, public int $uomId, public int $warehouseId, public float $qty, public ?int $storageLocationId = null, public float $qtyBonus = 0, public ?float $unitCost = null, public ?int $batchId = null, public ?int $serialId = null, public array $meta = []) {}
}
