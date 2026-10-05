<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Bridges\Support\MappingKeyGuard;
use ESolution\Inventory\Bridges\Support\ServiceCodeResolver;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\AccountingJournalGateway;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\Models\Document;

final class ExternalAccountingBridge implements AccountingBridge
{
    public function __construct(
        private readonly AccountingJournalGateway $gateway,
        private readonly ServiceCodeResolver $serviceCodes,
        private readonly MappingKeyGuard $mappingKeys,
    ) {}

    public function post(Document $document, AccountingPostingData $data): ?string
    {
        $serviceCode = $this->serviceCodes->resolve($document->document_type, $data->serviceCode);
        if ($serviceCode === null) {
            return null;
        }

        $this->mappingKeys->assertSafe($data->additionalJournalLines, $serviceCode);
        $prefix = strtolower($serviceCode);
        $inventoryLines = $data->direction === 'in'
            ? [['mapping_key' => "{$prefix}_inventory_d", 'amount' => $data->totalCost]]
            : [
                ['mapping_key' => "{$prefix}_cogs_d", 'amount' => $data->totalCost],
                ['mapping_key' => "{$prefix}_inventory_k", 'amount' => $data->totalCost],
            ];

        return $this->gateway->post([
            'service_code' => $serviceCode,
            'trx_date' => $document->trx_date?->toDateString(),
            'source_type' => $document->getMorphClass(),
            'source_id' => $document->getKey(),
            'items' => array_merge($data->additionalJournalLines, $inventoryLines),
        ], $data->tenantIdentity);
    }

    public function reverse(Document $originalDocument, string $reason): void
    {
        $journalId = $this->gateway->findOriginalJournalId(
            $originalDocument->getMorphClass(),
            $originalDocument->getKey(),
        );
        if ($journalId === null) {
            return;
        }

        $context = (array) ($originalDocument->meta['_accounting_context'] ?? []);
        $this->gateway->reverse($journalId, $reason, $context['tenant_identity'] ?? null);
    }
}
