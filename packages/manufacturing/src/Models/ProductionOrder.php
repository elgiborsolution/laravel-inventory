<?php

namespace ESolution\InventoryManufacturing\Models;

use ESolution\Inventory\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    protected $table = 'invm_production_orders';

    protected $guarded = [];

    protected $casts = [
        'planned_qty' => 'float',
        'actual_output_qty' => 'float',
        'actual_component_cost' => 'float',
        'output_unit_cost' => 'float',
        'completed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class);
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function consumptionDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'consumption_document_id');
    }

    public function receiptDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'receipt_document_id');
    }

    public function variances(): HasMany
    {
        return $this->hasMany(ProductionVariance::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->getOriginal('status') === 'completed') {
            throw new \LogicException('Completed Production Orders are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->status === 'completed') {
            throw new \LogicException('Completed Production Orders cannot be deleted.');
        }

        return parent::delete();
    }
}
