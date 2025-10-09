<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model {
    protected $table = 'inv_branches';
    protected $fillable = ['code','name','account_overrides'];
    protected $casts = ['account_overrides'=>'array'];
}
