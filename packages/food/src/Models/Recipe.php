<?php

namespace ESolution\InventoryFood\Models;

use ESolution\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $table = 'invf_recipes';

    protected $guarded = [];

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }
}
