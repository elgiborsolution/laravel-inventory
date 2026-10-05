<?php

namespace ESolution\InventoryManufacturing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomComponent extends Model
{
    protected $table = 'invm_bom_components';

    protected $guarded = [];

    protected $casts = ['qty' => 'float'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'bom_version_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->bom_version_id !== null) {
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
        $version = BomVersion::query()->findOrFail($this->bom_version_id);
        if ($version->status !== 'draft' || $version->productionOrders()->exists()) {
            throw new \LogicException('Components of an activated or used BOM version are immutable.');
        }
    }
}
