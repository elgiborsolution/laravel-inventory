<?php

namespace ESolution\InventoryLibrary\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $table = 'invl_holds';
    protected $guarded = [];
    protected $casts = ['expires_at' => 'datetime', 'ready_until' => 'datetime'];
}
