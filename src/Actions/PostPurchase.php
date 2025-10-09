<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\Document;

class PostPurchase extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            foreach ($doc->lines as $line){
                $qty = $line->qty; $unit = $line->unit_cost ?? 0;
                $this->costing($line)->receive($line, $qty, $unit);
                $this->pipeline()->move($line, $qty, null, 'gudang', $unit, 'in');

                $this->journal()->post($doc->date, "Purchase {$doc->ref}", [
                    ['account'=>inv_cfg('accounts.inventory'),'dc'=>'D','amount'=>$qty*$unit],
                    ['account'=>inv_cfg('accounts.ap'),'dc'=>'C','amount'=>$qty*$unit],
                ], $doc->id);
            }
            return $doc;
        });
    }
}
