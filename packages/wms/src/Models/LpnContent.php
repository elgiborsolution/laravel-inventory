<?php

namespace ESolution\InventoryWms\Models;

use Illuminate\Database\Eloquent\Model;

class LpnContent extends Model
{
    protected $table = 'invw_lpn_contents';

    protected $guarded = [];

    protected $casts = ['qty' => 'float'];
}
