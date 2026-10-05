<?php

namespace ESolution\InventoryManufacturing\DTO;

final class ProductionOrderData
{
    public function __construct(
        public readonly string $orderNo,
        public readonly int $bomVersionId,
        public readonly int $organizationId,
        public readonly int $warehouseId,
        public readonly float $plannedQty,
        public readonly string $sourceMode = 'mts',
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
        public readonly ?int $parentOrderId = null,
        public readonly array $meta = [],
    ) {
        if ($orderNo === '' || $plannedQty <= 0) {
            throw new \InvalidArgumentException('Production Order requires a number and positive planned quantity.');
        }
    }
}
