<?php

namespace ESolution\InventoryRetail\Services;

use ESolution\Inventory\Events\DocumentPosted;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\InventoryRetail\Models\ConsignmentSettlement;

final class SettlementRecorder
{
    public function __construct(private readonly ConsignmentTermsService $terms) {}

    public function handle(DocumentPosted $event): void
    {
        $document = $event->document;
        if ($document->document_type !== 'sales_delivery') {
            return;
        }

        foreach ($document->lines()->orderBy('line_no')->get() as $line) {
            $this->recordLine($line);
        }
    }

    private function recordLine(DocumentLine $line): void
    {
        $term = $this->terms->resolve(
            (int) $line->item_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        );
        if ($term === null) {
            return;
        }

        ConsignmentSettlement::query()->firstOrCreate([
            'document_line_id' => $line->getKey(),
        ], [
            'item_id' => $line->item_id,
            'consignment_term_id' => $term->getKey(),
            'supplier_party_type' => $term->supplier_party_type,
            'supplier_party_id' => $term->supplier_party_id,
            'qty_sold' => (float) $line->qty + (float) $line->qty_bonus,
            'sale_date' => $line->document()->value('trx_date'),
            'periodicity' => $term->settlement_periodicity,
            'status' => 'pending',
        ]);
    }
}
