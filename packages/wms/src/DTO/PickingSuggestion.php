<?php

namespace ESolution\InventoryWms\DTO;

final class PickingSuggestion
{
    public function __construct(
        public readonly int $locationId,
        public readonly ?int $batchId,
        public readonly float $qty,
    ) {}
}
