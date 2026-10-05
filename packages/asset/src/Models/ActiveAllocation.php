<?php

namespace ESolution\InventoryAsset\Models;

use ESolution\Inventory\Models\Serial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inva_active_allocations';

    protected $guarded = [];

    public function serial(): BelongsTo
    {
        return $this->belongsTo(Serial::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(AssetCheckout::class, 'checkout_id');
    }
}
