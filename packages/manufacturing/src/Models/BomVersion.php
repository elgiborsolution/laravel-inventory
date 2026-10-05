<?php

namespace ESolution\InventoryManufacturing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BomVersion extends Model
{
    protected $table = 'invm_bom_versions';

    protected $guarded = [];

    protected $casts = [
        'output_qty' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'activated_at' => 'datetime',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(BomComponent::class)->orderBy('sequence')->orderBy('id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists
            && ($this->getOriginal('status') !== 'draft' || $this->productionOrders()->exists())) {
            throw new \LogicException('Activated or used BOM versions are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->status !== 'draft' || $this->productionOrders()->exists()) {
            throw new \LogicException('Activated or used BOM versions cannot be deleted.');
        }

        return parent::delete();
    }
}
