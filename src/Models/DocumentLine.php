<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentLine extends Model
{
    protected $table = 'inv_document_lines';
    protected $guarded = [];
    protected $casts = ['meta' => 'array'];
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    } public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    } public function ledgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    public function reservationConsumptions(): HasMany
    {
        return $this->hasMany(ReservationConsumption::class);
    }
}
