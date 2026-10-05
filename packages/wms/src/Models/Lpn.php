<?php

namespace ESolution\InventoryWms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lpn extends Model
{
    protected $table = 'invw_lpns';

    protected $guarded = [];

    public function contents(): HasMany
    {
        return $this->hasMany(LpnContent::class);
    }
}
