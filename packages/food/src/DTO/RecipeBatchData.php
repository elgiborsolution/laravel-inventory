<?php

namespace ESolution\InventoryFood\DTO;

final class RecipeBatchData
{
    public function __construct(
        public readonly string $batchNo,
        public readonly int $recipeVersionId,
        public readonly int $organizationId,
        public readonly int $warehouseId,
        public readonly float $plannedQty,
        public readonly string $mode = 'mts',
        public readonly ?int $sourceDocumentId = null,
        public readonly ?int $sourceLineId = null,
        public readonly ?int $outputBatchId = null,
        public readonly array $meta = [],
    ) {
        if ($batchNo === '' || $plannedQty <= 0) {
            throw new \InvalidArgumentException('RecipeBatch requires a number and positive planned quantity.');
        }
        if (! in_array($mode, ['mts', 'mto'], true)) {
            throw new \InvalidArgumentException("Unsupported RecipeBatch mode '{$mode}'.");
        }
        if ($mode === 'mto' && ($sourceDocumentId === null || $sourceLineId === null)) {
            throw new \InvalidArgumentException('MTO RecipeBatch requires source document and line references.');
        }
    }
}
