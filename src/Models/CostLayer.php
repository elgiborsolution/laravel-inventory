<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class CostLayer extends Model
{
    protected $table = 'inv_cost_layers';
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime','is_negative' => 'boolean'];
}
