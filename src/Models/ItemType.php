<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ItemType extends Model {
    protected $table = 'inv_item_types';
    protected $fillable = ['code','name','valuation','stages'];
    protected $casts = ['stages'=>'array'];
}
