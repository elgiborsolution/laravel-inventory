<?php

namespace ESolution\InventoryWms\Models;

use Illuminate\Database\Eloquent\Model;

class PutAwayRule extends Model
{
    protected $table = 'invw_put_away_rules';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
