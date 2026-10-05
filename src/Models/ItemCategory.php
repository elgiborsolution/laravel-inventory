<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model
{
    protected $table = 'inv_item_categories';
    protected $guarded = [];
    protected $casts = ['policies' => 'array'];
}
