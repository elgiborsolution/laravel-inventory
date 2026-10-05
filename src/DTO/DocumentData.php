<?php

namespace ESolution\Inventory\DTO;

final class DocumentData
{
    /**
     * @param list<LineData>                 $lines
     * @param list<array<string, mixed>>       $additionalJournalLines
     * @param list<ReservationConsumptionData> $reservationConsumptions
     */
    public function __construct(
        public string $type,
        public int $organizationId,
        public string $trxDate,
        public array $lines,
        public ?string $externalId = null,
        public string $sourceType = 'inventory',
        public ?string $sourceId = null,
        public ?string $partyType = null,
        public ?string $partyId = null,
        public array $meta = [],
        public array $additionalJournalLines = [],
        public ?string $accountingServiceCode = null,
        public mixed $tenantIdentity = null,
        public string $approvalAction = 'create',
        public array $approvalData = [],
        public array $approvalMetadata = [],
        public array $reservationConsumptions = [],
    ) {}
}
