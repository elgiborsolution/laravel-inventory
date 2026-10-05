<?php

namespace ESolution\InventoryRetail\Models;

use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConsignmentSettlement extends Model
{
    protected $table = 'invr_consignment_settlements';

    protected $guarded = [];

    protected $casts = [
        'qty_sold' => 'float',
        'sale_date' => 'date',
        'settled_at' => 'datetime',
    ];

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(ConsignmentTerm::class, 'consignment_term_id');
    }
}
