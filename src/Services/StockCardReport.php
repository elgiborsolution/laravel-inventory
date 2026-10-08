<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Models\CostAdjustment;
use ESolution\Inventory\Models\StockLedger;

/** Posting-order audit report; transaction dates are labels, not a recosting order. */
final class StockCardReport
{
    /** @return list<array<string, mixed>> */
    public function forItem(int $itemId, int $warehouseId, ?int $storageLocationId = null): array
    {
        $entries = StockLedger::query()->with('documentLine.document')
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->when($storageLocationId !== null, fn($q) => $q->where('storage_location_id', $storageLocationId))
            ->orderBy('id')->get();
        $quantity = 0.0;
        $value = 0.0;
        $sales = 0.0;
        $salesQuantity = 0.0;
        $completeSalesPricing = true;
        $rows = [];
        // One line may consume multiple layers. Aggregate without duplicating sale revenue.
        foreach ($entries->groupBy(fn(StockLedger $entry): string => $entry->document_line_id . ':' . $entry->direction) as $group) {
            $entry = $group->first();
            $line = $entry->documentLine;
            $document = $line->document;
            $direction = $entry->direction;
            $qty = (float) $group->sum('qty');
            $amount = (float) $group->sum('amount');
            $bonus = (float) $group->sum('qty_bonus');
            $sign = $direction === 'in' ? 1 : -1;
            $quantity = round($quantity + $sign * $qty, 6);
            $settlement = $direction === 'in' ? (float) CostAdjustment::query()
                ->whereIn('receipt_layer_id', $group->pluck('cost_layer_id'))->sum('amount_delta') : 0.0;
            $value = round($value + $sign * $amount - $settlement, 6);
            $pricing = $line->meta['_inventory_pricing'] ?? null;
            $isSale = $direction === 'out' && in_array($document->document_type, ['sale', 'sales_delivery'], true);
            $isPurchase = in_array($document->document_type, ['purchase', 'purchase_receipt'], true);
            $price = $pricing['price'] ?? ($isPurchase ? (float) $line->unit_cost : null);
            $discount = $pricing['discount'] ?? 0.0;
            $net = $pricing['net'] ?? $price;
            $total = $net === null ? null : round(($qty - $bonus) * $net, 6);
            $profit = $isSale ? ($total === null ? null : round($total - $amount, 6)) : 0.0;
            if ($isSale) {
                $completeSalesPricing = $completeSalesPricing && $total !== null;
                $sales += $total ?? 0.0;
                $salesQuantity += $qty;
            }
            $rows[] = [
                'document_id' => $document->id,
                'document_line_id' => $line->id,
                'date' => $document->trx_date->toDateString(),
                'document_ref' => $document->external_id,
                'description' => $document->document_type,
                'direction' => $direction,
                'qty' => $qty,
                'signed_qty' => $sign * $qty,
                'sales_price' => $price,
                'discount_amount' => $discount,
                'nett_price' => $net,
                'total_trx' => $total,
                'in_amount' => $direction === 'in' ? $amount : 0.0,
                'out_amount' => $direction === 'out' ? $amount : 0.0,
                'cogs' => $direction === 'out' ? $amount : 0.0,
                'profit_unit' => $profit === null ? null : ($qty > 0 ? $profit / $qty : 0.0),
                'profit_amount' => $profit,
                'balance_qty' => $quantity,
                'balance_amount' => $value,
                // Display convention from the business specification, distinct from balance cost.
                'average_cost' => $isSale ? 0.0 : ($quantity > 0 ? $value / $quantity : 0.0),
                'balance_average_cost' => $quantity > 0 ? $value / $quantity : 0.0,
                'running_total_sales' => $completeSalesPricing ? $sales : null,
                'running_avg_sales' => $completeSalesPricing ? ($salesQuantity > 0 ? $sales / $salesQuantity : 0.0) : null,
                'cost_adjustment_amount' => $settlement,
            ];
        }

        return $rows;
    }
}
