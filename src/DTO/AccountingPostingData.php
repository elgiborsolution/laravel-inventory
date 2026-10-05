<?php

namespace ESolution\Inventory\DTO;

final class AccountingPostingData
{
    /** @param list<array<string, mixed>> $additionalJournalLines */
    public function __construct(
        public readonly float $totalCost,
        public readonly string $direction,
        public readonly array $additionalJournalLines = [],
        public readonly ?string $serviceCode = null,
        public readonly mixed $tenantIdentity = null,
    ) {}
}
