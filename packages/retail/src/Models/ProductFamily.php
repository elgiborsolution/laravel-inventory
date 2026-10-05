<?php

namespace ESolution\InventoryRetail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $base_sku
 * @property string $base_name
 * @property int $item_category_id
 * @property int $base_uom_id
 * @property bool|null $is_active
 */
final class ProductFamily extends Model
{
    protected $table = 'invr_product_families';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function axes(): HasMany
    {
        return $this->hasMany(VariantAxis::class)->orderBy('sort_order')->orderBy('id');
    }

    public function variantLinks(): HasMany
    {
        return $this->hasMany(ItemVariantLink::class);
    }
}
