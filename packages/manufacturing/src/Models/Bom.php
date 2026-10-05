<?php

namespace ESolution\InventoryManufacturing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    protected $table = 'invm_boms';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function versions(): HasMany
    {
        return $this->hasMany(BomVersion::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists
            && $this->isDirty(['code', 'output_item_id'])
            && $this->versions()->exists()) {
            throw new \LogicException('BOM identity and output cannot change after a version exists.');
        }

        return parent::save($options);
    }
}
