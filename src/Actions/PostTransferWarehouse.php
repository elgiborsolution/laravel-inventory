<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\{Document, DocumentLine};

class PostTransferWarehouse extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            foreach ($doc->lines as $line){
                $qty = $line->qty;
                $unit = $this->costing($line)->consume($line, $qty);
                $this->pipeline()->move($line, $qty, 'gudang', 'intransit', $unit, 'out');

                $dest = new DocumentLine([
                    'document_id'=>$line->document_id,
                    'item_id'=>$line->item_id,
                    'branch_id'=>$line->branch_id,
                    'warehouse_id'=>$doc->meta['target_warehouse_id'],
                    'rack_id'=>$doc->meta['target_rack_id'] ?? null,
                    'qty'=>$qty,
                    'unit_cost'=>$unit,
                    'meta'=>['from_warehouse_id'=>$line->warehouse_id],
                ]);
                $dest->save();
                $this->costing($dest)->receive($dest, $qty, $unit);
                $this->pipeline()->move($dest, $qty, 'intransit', 'gudang', $unit, 'in');
            }
            return $doc;
        });
    }
}
