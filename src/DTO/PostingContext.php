<?php

namespace ESolution\Inventory\DTO;

final class PostingContext
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $scopeType,
        public readonly int $scopeId,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
    ) {}
}
