<?php

namespace ESolution\Inventory\DTO;

final class ReversalRequest
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $reason,
        public readonly ?string $externalId = null,
    ) {}
}
