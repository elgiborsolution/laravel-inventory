<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\Document;

class PostSale extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            foreach ($doc->lines as $line){
                $qty = $line->qty;

                // move through stages (no COGS yet)
                $stages = inv_cfg('item_type_stages')['regular'] ?? []; // simple: use 'regular' as default
                $from = 'gudang';
                foreach ($stages as $st){
                    $this->pipeline()->move($line, $qty, $from, $st, 0, 'out');
                    $from = $st;
                }

                // consume & recognize COGS on final
                $unit = $this->costing($line)->consume($line, $qty);
                $this->pipeline()->move($line, $qty, $from, 'delivered', $unit, 'out');

                $this->journal()->post($doc->date, "COGS {$doc->ref}", [
                    ['account'=>inv_cfg('accounts.cogs'),'dc'=>'D','amount'=>$qty*$unit],
                    ['account'=>inv_cfg('accounts.inventory'),'dc'=>'C','amount'=>$qty*$unit],
                ], $doc->id);
            }
            return $doc;
        });
    }
}
