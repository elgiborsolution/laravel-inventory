<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $table = 'inv_certificates';
    protected $guarded = [];
    protected $casts = ['issued_at' => 'date','expires_at' => 'date'];
}
