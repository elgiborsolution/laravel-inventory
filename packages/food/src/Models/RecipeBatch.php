<?php

namespace ESolution\InventoryFood\Models;

use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeBatch extends Model
{
    protected $table = 'invf_recipe_batches';

    protected $guarded = [];

    protected $casts = [
        'planned_qty' => 'float',
        'actual_output_qty' => 'float',
        'actual_component_cost' => 'float',
        'output_unit_cost' => 'float',
        'completed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function outputBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'output_batch_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function consumptionDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'consumption_document_id');
    }

    public function receiptDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'receipt_document_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->getOriginal('status') === 'completed') {
            throw new \LogicException('Completed RecipeBatches are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->status === 'completed') {
            throw new \LogicException('Completed RecipeBatches cannot be deleted.');
        }

        return parent::delete();
    }
}
