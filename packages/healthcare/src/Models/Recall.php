<?php

namespace ESolution\InventoryHealthcare\Models;

use ESolution\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recall extends Model
{
    protected $table = 'invh_recalls';

    protected $guarded = [];

    protected $casts = [
        'recalled_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
