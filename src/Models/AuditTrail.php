<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class AuditTrail extends Model
{
    public $timestamps = false;
    protected $table = 'inv_audit_trails';
    protected $guarded = [];
    protected $casts = ['context' => 'array','created_at' => 'datetime'];
}
