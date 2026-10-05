<?php

namespace ESolution\InventoryAsset\Services;

use ESolution\InventoryAsset\Contracts\OverdueNotifier;
use ESolution\InventoryAsset\Models\AssetCheckout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class OverdueService
{
    public function __construct(private readonly OverdueNotifier $notifier) {}

    /** @return Collection<int, AssetCheckout> */
    public function detect(?Carbon $asOf = null): Collection
    {
        $time = $asOf ?? now();
        $overdue = AssetCheckout::query()
            ->where('status', 'active')
            ->whereNotNull('due_at')
            ->where('due_at', '<', $time)
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        foreach ($overdue as $checkout) {
            $this->notifier->notify($checkout);
        }

        return $overdue;
    }
}
