<?php

namespace ESolution\InventoryWms\Models;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StorageLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationProfile extends Model
{
    protected $table = 'invw_location_profiles';

    protected $guarded = [];

    protected $casts = [
        'capacity_qty' => 'float',
        'put_away_enabled' => 'boolean',
        'picking_enabled' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    public function dedicatedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'dedicated_item_id');
    }

    public function storageLocation(): StorageLocation
    {
        return StorageLocation::query()->findOrFail($this->storage_location_id);
    }
}
