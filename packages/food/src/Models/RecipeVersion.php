<?php

namespace ESolution\InventoryFood\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeVersion extends Model
{
    protected $table = 'invf_recipe_versions';

    protected $guarded = [];

    protected $casts = [
        'output_qty' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'published_at' => 'datetime',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class)->orderBy('sequence')->orderBy('id');
    }

    public function recipeBatches(): HasMany
    {
        return $this->hasMany(RecipeBatch::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists
            && ($this->getOriginal('status') !== 'draft' || $this->recipeBatches()->exists())) {
            throw new \LogicException('Published or used Recipe versions are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->status !== 'draft' || $this->recipeBatches()->exists()) {
            throw new \LogicException('Published or used Recipe versions cannot be deleted.');
        }

        return parent::delete();
    }
}
