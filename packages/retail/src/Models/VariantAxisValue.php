<?php

namespace ESolution\InventoryRetail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $variant_axis_id
 * @property string $code
 * @property string $value
 */
final class VariantAxisValue extends Model
{
    protected $table = 'invr_variant_axis_values';

    protected $guarded = [];

    public function axis(): BelongsTo
    {
        return $this->belongsTo(VariantAxis::class, 'variant_axis_id');
    }
}
