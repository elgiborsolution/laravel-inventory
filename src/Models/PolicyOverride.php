<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

final class PolicyOverride extends Model
{
    protected $table = 'inv_policy_overrides';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];
}
