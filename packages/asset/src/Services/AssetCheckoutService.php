<?php

namespace ESolution\InventoryAsset\Services;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryAsset\DTO\CheckoutData;
use ESolution\InventoryAsset\Models\ActiveAllocation;
use ESolution\InventoryAsset\Models\AssetCheckout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AssetCheckoutService
{
    public function __construct(private readonly InventoryManager $inventory) {}

    public function checkout(CheckoutData $data): AssetCheckout
    {
        return DB::transaction(function () use ($data): AssetCheckout {
            // Serial is the first lock anchor. Availability and duplicate checks
            // deliberately happen after this lock and inside this transaction.
            $serial = Serial::query()->lockForUpdate()->findOrFail($data->serialId);
            $item = Item::query()->findOrFail($serial->item_id);
            $this->validateAsset($item, $serial, $data->warehouseId);

            $existing = AssetCheckout::query()
                ->where('checkout_no', $data->checkoutNo)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->assertSamePayload($existing, $data);
            }

            if (ActiveAllocation::query()->where('serial_id', $serial->getKey())->exists()) {
                throw new \DomainException('Asset serial already has an active allocation.');
            }
            if ($this->inventory->availability((int) $item->getKey(), $data->warehouseId)->availableQty() < 1) {
                throw new \DomainException('Asset serial has no available stock to reserve.');
            }

            $checkedOutAt = Carbon::parse($data->checkedOutAt ?? now());
            $dueAt = $data->dueAt === null ? null : Carbon::parse($data->dueAt);
            if ($dueAt !== null && $dueAt->lte($checkedOutAt)) {
                throw new \DomainException('Asset checkout due date must be after checkout time.');
            }

            $reservation = $this->inventory->reserve(
                (int) $item->getKey(),
                1,
                $data->warehouseId,
                AssetCheckout::class,
                $data->checkoutNo,
            );
            $checkout = AssetCheckout::query()->create([
                'checkout_no' => $data->checkoutNo,
                'item_id' => $item->getKey(),
                'serial_id' => $serial->getKey(),
                'warehouse_id' => $data->warehouseId,
                'reservation_id' => $reservation->getKey(),
                'borrower_type' => $data->borrowerType,
                'borrower_id' => $data->borrowerId,
                'status' => 'active',
                'checked_out_at' => $checkedOutAt,
                'due_at' => $dueAt,
                'meta' => $data->meta,
            ]);
            ActiveAllocation::query()->create([
                'serial_id' => $serial->getKey(),
                'checkout_id' => $checkout->getKey(),
            ]);

            return $checkout->load('serial', 'reservation', 'activeAllocation');
        }, 3);
    }

    public function checkin(int $checkoutId, ?string $checkedInAt = null): AssetCheckout
    {
        $snapshot = AssetCheckout::query()->findOrFail($checkoutId);

        return DB::transaction(function () use ($checkoutId, $checkedInAt, $snapshot): AssetCheckout {
            Serial::query()->lockForUpdate()->findOrFail($snapshot->serial_id);
            $checkout = AssetCheckout::query()->lockForUpdate()->findOrFail($checkoutId);
            if ($checkout->status === 'checked_in') {
                return $checkout->load('serial', 'reservation');
            }
            if ($checkout->status !== 'active') {
                throw new \DomainException('Only an active Asset checkout can be checked in.');
            }

            $allocation = ActiveAllocation::query()
                ->where('checkout_id', $checkout->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->inventory->release((int) $checkout->reservation_id);
            $allocation->delete();
            $checkout->forceFill([
                'status' => 'checked_in',
                'checked_in_at' => Carbon::parse($checkedInAt ?? now()),
            ])->save();

            return $checkout->refresh()->load('serial', 'reservation');
        }, 3);
    }

    private function validateAsset(Item $item, Serial $serial, int $warehouseId): void
    {
        $allowed = (array) config('inventory-asset.allowed_item_types', ['stock']);
        if (! $item->is_active || ! in_array($item->item_type, $allowed, true)) {
            throw new \DomainException("Asset checkout does not allow Item Type '{$item->item_type}' or an inactive Item.");
        }
        if (! (bool) (($item->tracking ?? [])['asset_checkout_enabled'] ?? false)) {
            throw new \DomainException('Asset checkout requires the Asset tracking preset.');
        }
        if ($serial->status !== 'in_stock'
            || (int) $serial->warehouse_id !== $warehouseId
            || (int) $serial->item_id !== (int) $item->getKey()) {
            throw new \DomainException('Asset serial is not valid and in stock at the requested warehouse.');
        }
    }

    private function assertSamePayload(AssetCheckout $checkout, CheckoutData $data): AssetCheckout
    {
        $same = (int) $checkout->serial_id === $data->serialId
            && (int) $checkout->warehouse_id === $data->warehouseId
            && $checkout->borrower_type === $data->borrowerType
            && $checkout->borrower_id === $data->borrowerId;
        if (! $same) {
            throw new \DomainException('Asset checkout number was reused with a different payload.');
        }

        return $checkout->load('serial', 'reservation', 'activeAllocation');
    }
}
