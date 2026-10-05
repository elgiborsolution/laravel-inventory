<?php

namespace ESolution\InventoryLibrary\Models;

use Illuminate\Database\Eloquent\Model;

class Circulation extends Model
{
    protected $table = 'invl_circulations';
    protected $guarded = [];
    protected $casts = ['checked_out_at' => 'datetime', 'due_at' => 'datetime', 'checked_in_at' => 'datetime'];
    public function getIsOverdueAttribute(): bool
    {
        return $this->checked_in_at === null && $this->due_at->lt(now());
    }
}
