<?php

namespace ESolution\Inventory\DTO;

final class ApprovalContext
{
    /**
     * @param array<string, mixed>       $data
     * @param list<array<string, mixed>> $detailData
     * @param array<string, mixed>       $metadata
     */
    public function __construct(
        public readonly string $action,
        public readonly array $data,
        public readonly array $detailData,
        public readonly array $metadata = [],
        public readonly ?string $tenantId = null,
    ) {}
}
