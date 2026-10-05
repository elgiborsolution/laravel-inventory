<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockCard extends Model
{
    protected $table = 'inv_stock_cards';
    protected $guarded = [];
    protected $casts = ['as_of' => 'date'];
}
