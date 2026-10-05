<?php

namespace ESolution\InventoryProject\Models;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Organization;
use ESolution\Inventory\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectAllocation extends Model
{
    protected $table = 'invp_project_allocations';

    protected $guarded = [];

    protected $casts = ['meta' => 'array'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'site_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function sourceAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_allocation_id');
    }

    public function derivedAllocations(): HasMany
    {
        return $this->hasMany(self::class, 'source_allocation_id');
    }
}
