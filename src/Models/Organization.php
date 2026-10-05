<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'inv_organizations';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
}
