<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    protected $table = 'inv_batches';
    protected $guarded = [];
    protected $casts = ['manufactured_at' => 'date','expires_at' => 'date'];
}
