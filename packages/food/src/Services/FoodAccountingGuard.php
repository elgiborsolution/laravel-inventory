<?php

namespace ESolution\InventoryFood\Services;

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Contracts\AccountingBridge;

final class FoodAccountingGuard
{
    public function __construct(private readonly AccountingBridge $accounting) {}

    public function assertDisabled(): void
    {
        if ((bool) config('inventory.accounting.enabled', false)
            || (bool) config('inventory-food.accounting.enabled', false)
            || ! $this->accounting instanceof NullAccountingBridge) {
            $reason = (string) config(
                'inventory-food.accounting.blocked_reason',
                'Food accounting service codes are not verified.',
            );

            throw new \DomainException("Food accounting is fail-closed: {$reason}");
        }
    }
}
