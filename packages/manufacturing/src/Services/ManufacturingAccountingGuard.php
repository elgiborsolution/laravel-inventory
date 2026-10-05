<?php

namespace ESolution\InventoryManufacturing\Services;

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Contracts\AccountingBridge;

final class ManufacturingAccountingGuard
{
    public function __construct(private readonly AccountingBridge $accounting) {}

    public function assertDisabled(): void
    {
        if ((bool) config('inventory.accounting.enabled', false)
            || (bool) config('inventory-manufacturing.accounting.enabled', false)
            || ! $this->accounting instanceof NullAccountingBridge) {
            $reason = (string) config(
                'inventory-manufacturing.accounting.blocked_reason',
                'Manufacturing accounting service codes are not verified.',
            );

            throw new \DomainException("Manufacturing accounting is fail-closed: {$reason}");
        }
    }
}
