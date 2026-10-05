<?php

namespace ESolution\InventoryFood\Models;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeComponent extends Model
{
    protected $table = 'invf_recipe_components';

    protected $guarded = [];

    protected $casts = ['qty' => 'float'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'recipe_version_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->recipe_version_id !== null) {
            $this->assertVersionIsEditable();
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        $this->assertVersionIsEditable();

        return parent::delete();
    }

    private function assertVersionIsEditable(): void
    {
        $version = RecipeVersion::query()->findOrFail($this->recipe_version_id);
        if ($version->status !== 'draft' || $version->recipeBatches()->exists()) {
            throw new \LogicException('Components of a published or used Recipe version are immutable.');
        }
    }
}
