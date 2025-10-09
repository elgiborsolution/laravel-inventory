<?php
namespace ESolution\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\Models\Document;

class PostPurchaseReturn extends BaseAction {
    public function handle(Document $doc){
        return DB::transaction(function() use ($doc){
            foreach ($doc->lines as $line){
                $qty = $line->qty;
                $unit = $line->unit_cost ?? 0; // expected supplier invoice cost
                // for simplicity: consume current layers with FIFO; variance to gain/loss
                $actualUnit = $this->costing($line)->consume($line, $qty);
                $this->pipeline()->move($line, $qty, 'gudang', 'supplier', $actualUnit, 'out');

                $entries = [
                    ['account'=>inv_cfg('accounts.ap'),'dc'=>'D','amount'=>$qty*$unit],
                    ['account'=>inv_cfg('accounts.inventory'),'dc'=>'C','amount'=>$qty*$actualUnit],
                ];
                $diff = ($actualUnit - $unit) * $qty;
                if ($diff != 0){
                    $entries[] = [
                        'account'=> $diff>0 ? inv_cfg('accounts.inventory_loss') : inv_cfg('accounts.inventory_gain'),
                        'dc'     => $diff>0 ? 'D' : 'C',
                        'amount' => abs($diff),
                    ];
                }
                $this->journal()->post($doc->date, "Purchase Return {$doc->ref}", $entries, $doc->id);
            }
            return $doc;
        });
    }
}
