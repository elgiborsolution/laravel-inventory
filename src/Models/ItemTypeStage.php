<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ItemTypeStage extends Model {
    protected $table = 'inv_item_type_stages';
    protected $fillable = ['item_type_id','stage_id','order'];
}
