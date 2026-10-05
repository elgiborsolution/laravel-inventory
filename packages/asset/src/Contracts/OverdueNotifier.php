<?php

namespace ESolution\InventoryAsset\Contracts;

use ESolution\InventoryAsset\Models\AssetCheckout;

interface OverdueNotifier
{
    public function notify(AssetCheckout $checkout): void;
}
