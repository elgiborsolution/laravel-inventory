<?php

namespace ESolution\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model
{
    protected $table = 'inv_stock_ledgers';
    protected $guarded = [];
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Posted inventory ledger entries are immutable.');
        } return parent::save($options);
    } public function delete(): ?bool
    {
        throw new \LogicException('Posted inventory ledger entries cannot be deleted.');
    }
}
