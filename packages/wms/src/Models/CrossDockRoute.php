<?php

namespace ESolution\InventoryWms\Models;

use Illuminate\Database\Eloquent\Model;

class CrossDockRoute extends Model
{
    protected $table = 'invw_cross_dock_routes';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
