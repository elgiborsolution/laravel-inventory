<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model {
    protected $table = 'inv_stock_ledgers';
    protected $fillable = [
        'document_id','document_line_id','item_id','branch_id','warehouse_id','rack_id',
        'stage_from','stage_to','direction','qty','unit_cost','amount'
    ];
}
