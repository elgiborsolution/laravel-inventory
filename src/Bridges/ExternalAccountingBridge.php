<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Bridges\Support\MappingKeyGuard;
use ESolution\Inventory\Bridges\Support\ServiceCodeResolver;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\AccountingJournalGateway;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\Exceptions\AccountingMappingIncompleteException;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\StockLedger;

final class ExternalAccountingBridge implements AccountingBridge
{
    public function __construct(
        private readonly AccountingJournalGateway $gateway,
        private readonly ServiceCodeResolver $serviceCodes,
        private readonly MappingKeyGuard $mappingKeys,
    ) {}

    public function post(Document $document, AccountingPostingData $data): ?string
    {
        if (in_array($document->document_type, ['stock_count', 'stock_opname'], true)
            && ! StockLedger::query()->whereIn('document_line_id', $document->lines()->select('id'))->exists()) {
            return null;
        }
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

        if (in_array($document->document_type, [
            'positive_adjustment', 'negative_adjustment', 'stock_count', 'stock_opname', 'supplier_return', 'purchase_return',
        ], true)) {
            $inventoryLines = $this->businessLines($document, $serviceCode);
        }

        return $this->gateway->post([
            'service_code' => $serviceCode,
            'trx_date' => $document->trx_date?->toDateString(),
            'source_type' => $document->getMorphClass(),
            'source_id' => $document->getKey(),
            'items' => array_merge($data->additionalJournalLines, $inventoryLines),
        ], $data->tenantIdentity);
    }

    /** @return list<array{mapping_key: string, amount: float}> */
    private function businessLines(Document $document, string $serviceCode): array
    {
        $entries = StockLedger::query()->whereIn('document_line_id', $document->lines()->select('id'))->get();
        $in = (float) $entries->where('direction', 'in')->sum('amount');
        $out = (float) $entries->where('direction', 'out')->sum('amount');
        $amounts = ['inventory_debit' => $in, 'gain_credit' => $in, 'loss_debit' => $out, 'inventory_credit' => $out];
        if (in_array($document->document_type, ['supplier_return', 'purchase_return'], true)) {
            $refund = 0.0;
            foreach (DocumentLine::query()->where('document_id', $document->id)->get() as $line) {
                $net = $line->meta['_inventory_pricing']['net'] ?? null;
                if ($net === null) {
                    throw new AccountingMappingIncompleteException('Supplier return accounting requires transactionPrice (original invoice price) on every line.');
                }
                $refund += (float) $line->qty * (float) $net;
            }
            $refund = round($refund, 6);
            $amounts = [
                'payable_debit' => $refund, 'inventory_credit' => $out,
                'gain_credit' => max(0.0, $refund - $out), 'loss_debit' => max(0.0, $out - $refund),
            ];
        }
        $map = (array) config('inventory.accounting.document_mapping_keys.' . $document->document_type, []);
        $lines = [];
        foreach ($amounts as $role => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $key = $map[$role] ?? null;
            if (! is_string($key) || $key === '') {
                throw new AccountingMappingIncompleteException("Missing accounting mapping role '{$role}' for '{$document->document_type}'.");
            }
            $lines[] = ['mapping_key' => $key, 'amount' => round($amount, 6)];
        }
        $this->mappingKeys->assertSafe($lines, $serviceCode);

        return $lines;
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
