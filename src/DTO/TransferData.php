<?php

namespace ESolution\Inventory\DTO;

final class TransferData
{
    /** @param list<LineData> $lines Source warehouse lines, in the item's base UOM. */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $targetWarehouseId,
        public readonly string $trxDate,
        public readonly string $externalId,
        public readonly array $lines,
        public readonly string $sourceType = 'inventory',
        public readonly ?string $sourceId = null,
    ) {}
}
