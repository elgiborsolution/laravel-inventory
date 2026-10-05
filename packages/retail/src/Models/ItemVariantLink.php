<?php

namespace ESolution\InventoryRetail\Models;

use ESolution\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $product_family_id
 * @property int $item_id
 * @property string $combination_key
 */
final class ItemVariantLink extends Model
{
    protected $table = 'invr_item_variant_links';

    protected $guarded = [];

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function axisValues(): BelongsToMany
    {
        return $this->belongsToMany(
            VariantAxisValue::class,
            'invr_item_variant_link_values',
            'item_variant_link_id',
            'variant_axis_value_id',
        );
    }
}
