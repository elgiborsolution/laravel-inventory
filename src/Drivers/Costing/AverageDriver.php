<?php
namespace ESolution\Inventory\Drivers\Costing;

use ESolution\Inventory\Contracts\CostingDriver;
use ESolution\Inventory\Models\{DocumentLine, CostLayer};
use Illuminate\Support\Facades\DB;

class AverageDriver implements CostingDriver
{
    private function scopeLayers($q, DocumentLine $line){
        $q->where('item_id',$line->item_id);
        if (inv_cfg('valuation_scopes.per_branch'))    $q->where('branch_id',$line->branch_id);
        if (inv_cfg('valuation_scopes.per_warehouse')) $q->where('warehouse_id',$line->warehouse_id);
        if (inv_cfg('valuation_scopes.per_rack'))      $q->where('rack_id',$line->rack_id);
        else $q->whereNull('rack_id');
        return $q;
    }

    private function aggregate(DocumentLine $line): array
    {
        $q = CostLayer::query();
        $this->scopeLayers($q, $line);
        $layers = $q->get();
        $qty = 0; $amt = 0;
        foreach ($layers as $l){ $qty += $l->qty_remain; $amt += $l->qty_remain * $l->unit_cost; }
        $avg = $qty > 0 ? $amt/$qty : 0;
        return [$qty,$avg];
    }

    public function consume(DocumentLine $line, float $qty): float
    {
        [$qty0,$avg] = $this->aggregate($line);
        if ($qty0 < $qty) throw new \RuntimeException('Insufficient stock');
        // reduce proportionally from earliest layers (fallback to FIFO reduction for simplicity)
        $q = CostLayer::query(); $this->scopeLayers($q,$line);
        $layers = $q->orderBy('id')->lockForUpdate()->get();
        $remaining = $qty;
        foreach ($layers as $layer){
            if ($remaining<=0) break;
            $take = min($layer->qty_remain, $remaining);
            $layer->qty_remain -= $take; $layer->save(); $remaining -= $take;
        }
        return round($avg, 6);
    }

    public function receive(DocumentLine $line, float $qty, float $unitCost): void
    {
        // For simplicity, keep per-entry layer; average is computed on the fly.
        CostLayer::create([
            'item_id'=>$line->item_id,
            'branch_id'=>$line->branch_id,
            'warehouse_id'=>$line->warehouse_id,
            'rack_id'=>inv_cfg('valuation_scopes.per_rack') ? $line->rack_id : null,
            'qty_remain'=>$qty,
            'unit_cost'=>$unitCost,
            'source_document_id'=>$line->document_id,
        ]);
    }

    public function reverse(DocumentLine $line): void { /* optional */ }
}
