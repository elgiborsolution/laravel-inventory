<?php

namespace ESolution\InventoryLibrary\Services;

use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryLibrary\Models\Circulation;
use ESolution\InventoryLibrary\Models\Hold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CirculationService
{
    public function __construct(private readonly InventoryManager $inventory) {}

    public function hold(string $number, int $itemId, int $warehouseId, string $patronType, string $patronId, ?string $expiresAt = null): Hold
    {
        $this->identity($number, $patronType, $patronId);
        return DB::transaction(function () use ($number, $itemId, $warehouseId, $patronType, $patronId, $expiresAt): Hold {
            $this->lockItem($itemId);
            $expiry = $expiresAt === null ? null : Carbon::parse($expiresAt);
            $existing = Hold::where('hold_no', $number)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->item_id !== $itemId || (int) $existing->warehouse_id !== $warehouseId
                    || $existing->patron_type !== $patronType || $existing->patron_id !== $patronId
                    || $existing->expires_at?->toDateTimeString() !== $expiry?->toDateTimeString()) {
                    throw new \DomainException('Hold identity payload conflict.');
                }
                return $existing;
            }
            if ($expiry !== null && $expiry->lte(now())) {
                throw new \DomainException('Hold expiry must be in the future.');
            }
            return Hold::create(['hold_no' => $number, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
                'patron_type' => $patronType, 'patron_id' => $patronId, 'expires_at' => $expiry, 'status' => 'waiting']);
        }, 3);
    }

    public function fulfillNextHold(int $serialId): ?Hold
    {
        return DB::transaction(function () use ($serialId): ?Hold {
            $serial = $this->lockCopy($serialId);
            return $this->advance($serial);
        }, 3);
    }

    public function checkout(string $number, int $serialId, string $patronType, string $patronId, string $dueAt): Circulation
    {
        $this->identity($number, $patronType, $patronId);
        return DB::transaction(function () use ($number, $serialId, $patronType, $patronId, $dueAt): Circulation {
            $serial = $this->lockCopy($serialId);
            $due = Carbon::parse($dueAt);
            $existing = Circulation::where('loan_no', $number)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->serial_id !== $serialId || $existing->patron_type !== $patronType
                    || $existing->patron_id !== $patronId) {
                    throw new \DomainException('Circulation identity payload conflict.');
                }
                return $existing;
            }
            if ($due->lte(now())) {
                throw new \DomainException('Loan due date must be in the future.');
            }
            $this->advance($serial);
            $allocation = DB::table('invl_active_allocations')->where('serial_id', $serialId)->lockForUpdate()->first();
            $holdId = null;
            if ($allocation !== null) {
                if ($allocation->circulation_id !== null) {
                    throw new \DomainException('Copy already on loan.');
                }
                $hold = Hold::lockForUpdate()->findOrFail($allocation->hold_id);
                if ($hold->patron_type !== $patronType || $hold->patron_id !== $patronId) {
                    throw new \DomainException('Copy is ready for another patron.');
                }
                $holdId = $hold->id;
                $reservationId = $allocation->reservation_id;
                $hold->update(['status' => 'fulfilled']);
            } else {
                $this->assertAvailable($serial);
                $reservationId = $this->inventory->reserve((int) $serial->item_id, 1, (int) $serial->warehouse_id, Circulation::class, $number)->id;
            }
            $loan = Circulation::create(['loan_no' => $number, 'serial_id' => $serialId,
                'reservation_id' => $reservationId, 'hold_id' => $holdId,
                'patron_type' => $patronType, 'patron_id' => $patronId,
                'checked_out_at' => now(), 'due_at' => $due]);
            if ($allocation === null) {
                DB::table('invl_active_allocations')->insert(['serial_id' => $serialId, 'reservation_id' => $reservationId, 'circulation_id' => $loan->id]);
            } else {
                DB::table('invl_active_allocations')->where('serial_id', $serialId)->update(['circulation_id' => $loan->id, 'hold_id' => null]);
            }
            return $loan;
        }, 3);
    }

    public function checkin(int $loanId): Circulation
    {
        $snapshot = Circulation::findOrFail($loanId);
        return DB::transaction(function () use ($snapshot, $loanId): Circulation {
            $serial = $this->lockCopy((int) $snapshot->serial_id, false);
            $loan = Circulation::lockForUpdate()->findOrFail($loanId);
            if ($loan->checked_in_at !== null) {
                return $loan;
            }
            $this->inventory->release((int) $loan->reservation_id);
            $deleted = DB::table('invl_active_allocations')->where('serial_id', $serial->id)->where('circulation_id', $loanId)->delete();
            if ($deleted !== 1) {
                throw new \DomainException('Circulation active allocation is missing.');
            }
            $loan->update(['checked_in_at' => now()]);
            if ($serial->status === 'in_stock') {
                $this->advance($serial);
            }
            return $loan->refresh();
        }, 3);
    }

    public function renew(int $loanId, string $renewalNo, string $dueAt): Circulation
    {
        if ($renewalNo === '') {
            throw new \InvalidArgumentException('Renewal number required.');
        }
        $snapshot = Circulation::findOrFail($loanId);
        return DB::transaction(function () use ($snapshot, $loanId, $renewalNo, $dueAt): Circulation {
            $serial = $this->lockCopy((int) $snapshot->serial_id);
            $loan = Circulation::lockForUpdate()->findOrFail($loanId);
            $due = Carbon::parse($dueAt);
            $existing = DB::table('invl_renewals')->where('renewal_no', $renewalNo)->first();
            if ($existing !== null) {
                if ((int) $existing->circulation_id !== $loanId || $existing->due_at !== $due->toDateTimeString()) {
                    throw new \DomainException('Renewal identity payload conflict.');
                }
                return $loan;
            }
            if ($loan->checked_in_at !== null || $due->lte($loan->due_at) || $due->lte(now())) {
                throw new \DomainException('Renewal must extend an active loan into the future.');
            }
            if (Hold::where('item_id', $serial->item_id)->where('warehouse_id', $serial->warehouse_id)
                ->whereIn('status', ['waiting', 'ready'])
                ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
                throw new \DomainException('Renewal blocked by Hold queue.');
            }
            DB::table('invl_renewals')->insert(['renewal_no' => $renewalNo, 'circulation_id' => $loanId,
                'previous_due_at' => $loan->due_at, 'due_at' => $due, 'created_at' => now(), 'updated_at' => now()]);
            $loan->update(['due_at' => $due]);
            return $loan->refresh();
        }, 3);
    }

    public function recordFine(int $loanId, string $number, int $amountMinor, string $currency, string $reason): int
    {
        if ($number === '' || $amountMinor <= 0 || ! preg_match('/^[A-Z]{3}$/', $currency) || trim($reason) === '') {
            throw new \InvalidArgumentException('Fine requires identity, positive minor units, currency and reason.');
        }
        return DB::transaction(function () use ($loanId, $number, $amountMinor, $currency, $reason): int {
            Circulation::lockForUpdate()->findOrFail($loanId);
            $existing = DB::table('invl_fines')->where('fine_no', $number)->first();
            if ($existing !== null) {
                if ((int) $existing->circulation_id !== $loanId || (int) $existing->amount_minor !== $amountMinor
                    || $existing->currency !== $currency || $existing->reason !== $reason) {
                    throw new \DomainException('Fine identity payload conflict.');
                }
                return (int) $existing->id;
            }
            return DB::table('invl_fines')->insertGetId(['fine_no' => $number, 'circulation_id' => $loanId,
                'amount_minor' => $amountMinor, 'currency' => $currency, 'reason' => $reason,
                'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    /** Expire ready holds and try their copies again. Repeated execution is safe. */
    public function expireReadyHolds(): int
    {
        $ids = DB::table('invl_active_allocations as a')->join('invl_holds as h', 'h.id', '=', 'a.hold_id')
            ->where('h.status', 'ready')->where('h.ready_until', '<=', now())->pluck('a.serial_id');
        foreach ($ids as $id) {
            $this->fulfillNextHold((int) $id);
        }
        return $ids->count();
    }

    private function advance(Serial $serial): ?Hold
    {
        $allocation = DB::table('invl_active_allocations')->where('serial_id', $serial->id)->lockForUpdate()->first();
        if ($allocation !== null) {
            if ($allocation->circulation_id !== null) {
                return null;
            }
            $ready = Hold::lockForUpdate()->findOrFail($allocation->hold_id);
            if ($ready->ready_until->gt(now())) {
                return $ready;
            }
            $this->inventory->release((int) $allocation->reservation_id);
            DB::table('invl_active_allocations')->where('serial_id', $serial->id)->delete();
            $ready->update(['status' => 'expired']);
        }
        $queue = Hold::where('item_id', $serial->item_id)->where('warehouse_id', $serial->warehouse_id)
            ->where('status', 'waiting')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($queue as $hold) {
            if ($hold->expires_at !== null && $hold->expires_at->lte(now())) {
                $hold->update(['status' => 'expired']);
                continue;
            }
            if ($this->inventory->availability((int) $serial->item_id, (int) $serial->warehouse_id)->availableQty() < 1) {
                return null;
            }
            $hours = (int) config('inventory-library.ready_hours', 48);
            if ($hours < 1) {
                throw new \DomainException('Hold ready window must be positive.');
            }
            $until = now()->addHours($hours);
            if ($hold->expires_at !== null && $hold->expires_at->lt($until)) {
                $until = $hold->expires_at;
            }
            $reservation = $this->inventory->reserve((int) $serial->item_id, 1, (int) $serial->warehouse_id, Hold::class, $hold->hold_no);
            DB::table('invl_active_allocations')->insert(['serial_id' => $serial->id, 'reservation_id' => $reservation->id, 'hold_id' => $hold->id]);
            $hold->update(['status' => 'ready', 'ready_until' => $until]);
            return $hold->refresh();
        }
        return null;
    }

    private function lockCopy(int $serialId, bool $validate = true): Serial
    {
        $snapshot = Serial::findOrFail($serialId);
        // One lock order across checkout, queue, expiry, check-in, renewal and Core reservation.
        $item = $this->lockItem((int) $snapshot->item_id, $validate);
        $serial = Serial::lockForUpdate()->findOrFail($serialId);
        if ((int) $serial->item_id !== (int) $item->id || ($validate && ($serial->status !== 'in_stock' || $serial->warehouse_id === null))) {
            throw new \DomainException('Copy is unavailable.');
        }
        return $serial;
    }

    private function lockItem(int $itemId, bool $validate = true): Item
    {
        $item = Item::lockForUpdate()->findOrFail($itemId);
        if ($validate && (! $item->is_active || $item->item_type !== 'stock' || ! ($item->tracking['library_circulation_enabled'] ?? false))) {
            throw new \DomainException('Library requires an active stock Item with Library preset.');
        }
        return $item;
    }

    private function assertAvailable(Serial $serial): void
    {
        if ($this->inventory->availability((int) $serial->item_id, (int) $serial->warehouse_id)->availableQty() < 1) {
            throw new \DomainException('No available copy stock.');
        }
    }

    private function identity(string $number, string $type, string $id): void
    {
        if (trim($number) === '' || trim($type) === '' || trim($id) === '') {
            throw new \InvalidArgumentException('Identity and patron reference required.');
        }
    }
}
