<?php

namespace ESolution\InventoryManufacturing\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionVariance extends Model
{
    protected $table = 'invm_production_variances';

    protected $guarded = [];

    protected $casts = [
        'expected_qty' => 'float',
        'actual_qty' => 'float',
        'difference_qty' => 'float',
        'amount' => 'float',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Posted production variances are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Posted production variances cannot be deleted.');
    }
}
