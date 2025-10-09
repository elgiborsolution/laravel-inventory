<?php
namespace ESolution\Inventory\Drivers\Costing;

use ESolution\Inventory\Contracts\CostingDriver;
use ESolution\Inventory\Models\{DocumentLine, CostLayer};
use Illuminate\Support\Facades\DB;

class FifoDriver implements CostingDriver
{
    private function scopeLayers($q, DocumentLine $line){
        $q->where('item_id',$line->item_id);
        if (inv_cfg('valuation_scopes.per_branch'))    $q->where('branch_id',$line->branch_id);
        if (inv_cfg('valuation_scopes.per_warehouse')) $q->where('warehouse_id',$line->warehouse_id);
        if (inv_cfg('valuation_scopes.per_rack'))      $q->where('rack_id',$line->rack_id);
        else $q->whereNull('rack_id');
        return $q;
    }

    public function consume(DocumentLine $line, float $qty): float
    {
        $layersQ = CostLayer::query();
        $this->scopeLayers($layersQ, $line);
        $layers = $layersQ->orderBy('id')->lockForUpdate()->get();

        $remaining = $qty; $amt = 0; $taken = 0;
        foreach ($layers as $layer) {
            if ($remaining <= 0) break;
            $take = min($layer->qty_remain, $remaining);
            if ($take > 0) {
                $amt += $take * $layer->unit_cost;
                $taken += $take;
                $layer->qty_remain -= $take;
                $layer->save();
                $remaining -= $take;
            }
        }
        if ($remaining > 0) throw new \RuntimeException('Insufficient stock');

        return round($amt / max(1e-9,$taken), 6);
    }

    public function receive(DocumentLine $line, float $qty, float $unitCost): void
    {
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
