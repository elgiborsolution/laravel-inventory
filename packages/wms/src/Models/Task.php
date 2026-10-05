<?php

namespace ESolution\InventoryWms\Models;

use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $table = 'invw_tasks';

    protected $guarded = [];

    protected $casts = ['qty' => 'float', 'meta' => 'array'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class);
    }
}
