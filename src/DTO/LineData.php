<?php

namespace ESolution\Inventory\DTO;

final class LineData implements \JsonSerializable
{
    public function __construct(
        public int $itemId,
        public int $uomId,
        public int $warehouseId,
        public float $qty,
        public ?int $storageLocationId = null,
        public float $qtyBonus = 0,
        public ?float $unitCost = null,
        public ?int $batchId = null,
        public ?int $serialId = null,
        public array $meta = [],
        public ?float $transactionPrice = null,
        public float $discountPerUnit = 0,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $values = get_object_vars($this);
        if ($this->transactionPrice === null && $this->discountPerUnit === 0.0) {
            unset($values['transactionPrice'], $values['discountPerUnit']);
        }

        return $values;
    }
}
