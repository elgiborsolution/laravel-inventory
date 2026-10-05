<?php

namespace ESolution\InventoryAsset\Models;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Organization;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\Serial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class AssetCheckout extends Model
{
    protected $table = 'inva_checkouts';

    protected $guarded = [];

    protected $casts = [
        'checked_out_at' => 'datetime',
        'due_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = ['is_overdue'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(Serial::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'warehouse_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function activeAllocation(): HasOne
    {
        return $this->hasOne(ActiveAllocation::class, 'checkout_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdueAt(now());
    }

    public function isOverdueAt(Carbon $asOf): bool
    {
        return $this->status === 'active'
            && $this->due_at !== null
            && $this->due_at->lt($asOf);
    }
}
