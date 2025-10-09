<?php
namespace ESolution\Inventory\DTO;

class LineData {
    public function __construct(
        public int $itemId,
        public int $branchId,
        public int $warehouseId,
        public ?int $rackId = null,
        public float $qty = 0,
        public ?float $unitCost = null,
        public array $meta = [],
    ){}

    public static function make(
        int $itemId, int $branchId, int $warehouseId, ?int $rackId, float $qty, ?float $unitCost=null, array $meta=[]
    ): self {
        return new self($itemId,$branchId,$warehouseId,$rackId,$qty,$unitCost,$meta);
    }
}
