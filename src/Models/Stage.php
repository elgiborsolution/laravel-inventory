<?php
namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Stage extends Model {
    protected $table = 'inv_stages';
    protected $fillable = ['code','name'];
}
