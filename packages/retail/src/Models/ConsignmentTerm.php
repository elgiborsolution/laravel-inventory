<?php

namespace ESolution\InventoryRetail\Models;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StorageLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property null|float $reference_unit_cost
 * @property string $supplier_party_type
 * @property string $supplier_party_id
 * @property string $settlement_periodicity
 */
final class ConsignmentTerm extends Model
{
    protected $table = 'invr_item_consignment_terms';

    protected $guarded = [];

    protected $casts = [
        'reference_unit_cost' => 'float',
        'is_active' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }
}
