<?php

namespace ESolution\InventoryAutomotive\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PartUsageReport
{
    public function query(int $organizationId, ?string $workOrderType = null, ?string $workOrderId = null, ?string $vehicleType = null, ?string $vehicleId = null, ?int $itemId = null, ?int $serialId = null): Builder
    {
        return DB::table('inv_documents as d')
            ->join('inv_document_lines as l', 'l.document_id', '=', 'd.id')
            ->join('inv_stock_ledgers as s', 's.document_line_id', '=', 'l.id')
            ->where('d.organization_id', $organizationId)
            ->where('d.document_type', 'work_order_parts_issue')
            ->whereNotNull('d.posting_completed_at')
            ->where('s.direction', 'out')
            ->when($workOrderType !== null, fn(Builder $q) => $q->where('d.source_type', $workOrderType))
            ->when($workOrderId !== null, fn(Builder $q) => $q->where('d.source_id', $workOrderId))
            ->when($vehicleType !== null, fn(Builder $q) => $q->where('d.party_type', $vehicleType))
            ->when($vehicleId !== null, fn(Builder $q) => $q->where('d.party_id', $vehicleId))
            ->when($itemId !== null, fn(Builder $q) => $q->where('l.item_id', $itemId))
            ->when($serialId !== null, fn(Builder $q) => $q->where('l.serial_id', $serialId))
            ->select(['d.id as document_id', 'd.external_id', 'd.source_type as work_order_type', 'd.source_id as work_order_id', 'd.party_type as vehicle_type', 'd.party_id as vehicle_id', 'l.id as document_line_id', 'l.item_id', 'l.serial_id'])
            ->selectRaw('SUM(s.qty) as qty, SUM(s.amount) as amount')
            ->groupBy('d.id', 'd.external_id', 'd.source_type', 'd.source_id', 'd.party_type', 'd.party_id', 'l.id', 'l.item_id', 'l.serial_id')
            ->orderBy('d.id')->orderBy('l.id');
    }
}
