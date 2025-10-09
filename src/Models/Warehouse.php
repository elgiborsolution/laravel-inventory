<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model {
    protected $table = 'inv_warehouses';
    protected $fillable = ['branch_id','code','name'];
}
