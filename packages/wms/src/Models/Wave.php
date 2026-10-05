<?php

namespace ESolution\InventoryWms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Wave extends Model
{
    protected $table = 'invw_waves';

    protected $guarded = [];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'invw_wave_tasks');
    }
}
