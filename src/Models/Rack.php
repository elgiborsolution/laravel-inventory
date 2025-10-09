<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Rack extends Model {
    protected $table = 'inv_racks';
    protected $fillable = ['warehouse_id','code','name'];
}
