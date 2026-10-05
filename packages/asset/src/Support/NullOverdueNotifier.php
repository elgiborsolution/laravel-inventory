<?php

namespace ESolution\InventoryAsset\Support;

use ESolution\InventoryAsset\Contracts\OverdueNotifier;
use ESolution\InventoryAsset\Models\AssetCheckout;

final class NullOverdueNotifier implements OverdueNotifier
{
    public function notify(AssetCheckout $checkout): void {}
}
