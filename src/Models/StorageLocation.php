<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StorageLocation extends Model
{
    protected $table = 'inv_storage_locations';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
}
