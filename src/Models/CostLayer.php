<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class CostLayer extends Model {
    protected $table = 'inv_cost_layers';
    protected $fillable = ['item_id','branch_id','warehouse_id','rack_id','qty_remain','unit_cost','source_document_id'];
}
