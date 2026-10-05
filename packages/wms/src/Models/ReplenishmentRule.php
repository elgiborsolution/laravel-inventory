<?php

namespace ESolution\InventoryWms\Models;

use Illuminate\Database\Eloquent\Model;

class ReplenishmentRule extends Model
{
    protected $table = 'invw_replenishment_rules';

    protected $guarded = [];

    protected $casts = [
        'minimum_qty' => 'float',
        'target_qty' => 'float',
        'is_active' => 'boolean',
    ];
}
