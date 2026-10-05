<?php

namespace ESolution\InventoryRetail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $product_family_id
 * @property string $name
 */
final class VariantAxis extends Model
{
    protected $table = 'invr_variant_axes';

    protected $guarded = [];

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(VariantAxisValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
