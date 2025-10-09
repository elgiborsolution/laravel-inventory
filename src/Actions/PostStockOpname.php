<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\Document;

class PostStockOpname extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            foreach ($doc->lines as $line){
                $qty = $line->qty;
                $unit = $line->unit_cost ?? 0;
                $direction = $qty >= 0 ? 'in' : 'out';
                $q = abs($qty);

                if ($direction==='in'){
                    $this->costing($line)->receive($line, $q, $unit);
                    $this->pipeline()->move($line, $q, null, 'gudang', $unit, 'in');
                    $this->journal()->post($doc->date, "Opname Gain {$doc->ref}", [
                        ['account'=>inv_cfg('accounts.inventory'),'dc'=>'D','amount'=>$q*$unit],
                        ['account'=>inv_cfg('accounts.inventory_gain'),'dc'=>'C','amount'=>$q*$unit],
                    ], $doc->id);
                } else {
                    $actualUnit = $this->costing($line)->consume($line, $q);
                    $this->pipeline()->move($line, $q, 'gudang', 'adjusted', $actualUnit, 'out');
                    $this->journal()->post($doc->date, "Opname Loss {$doc->ref}", [
                        ['account'=>inv_cfg('accounts.inventory_loss'),'dc'=>'D','amount'=>$q*$actualUnit],
                        ['account'=>inv_cfg('accounts.inventory'),'dc'=>'C','amount'=>$q*$actualUnit],
                    ], $doc->id);
                }
            }
            return $doc;
        });
    }
}
