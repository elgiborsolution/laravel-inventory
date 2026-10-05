<?php

namespace ESolution\Inventory\Services;

use Carbon\CarbonInterface;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\Certificate;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Serial;
use Illuminate\Database\Eloquent\Builder;

final class TrackingPolicy
{
    public function validateLine(
        Item $item,
        ?Batch $batch,
        string $direction,
        LineData $line,
        CarbonInterface $trxDate,
    ): void {
        $tracking = (array) ($item->tracking ?? []);
        if ($direction === 'in') {
            if (($tracking['serial_required_on_receipt'] ?? false) && $line->serialId === null) {
                throw new \DomainException('Tracked Item receipt requires a serial.');
            }
            if (($tracking['batch_required_on_receipt'] ?? false) && $batch === null) {
                throw new \DomainException('This item requires a batch on receipt.');
            }
            if (($tracking['expiry_required_on_receipt'] ?? false) && $batch?->expires_at === null) {
                throw new \DomainException('This item requires batch expiry on receipt.');
            }
            if (array_key_exists('expired_receipt_dispositions', $tracking)
                && $batch?->expires_at !== null
                && $batch->expires_at->isBefore($trxDate->startOfDay())) {
                $allowed = (array) ($tracking['expired_receipt_dispositions'] ?? []);
                $disposition = $line->meta['disposition'] ?? null;
                if (! is_string($disposition) || ! in_array($disposition, $allowed, true)) {
                    throw new \DomainException('Expired batch receipt requires an allowed controlled disposition.');
                }
            }

            return;
        }

        if ($direction !== 'out') {
            return;
        }
        if (($tracking['serial_required_on_issue'] ?? false) && $line->serialId === null) {
            throw new \DomainException('Tracked Item issue requires a serial.');
        }

        foreach ((array) ($tracking['required_serial_certificates_on_issue'] ?? []) as $type) {
            $serial = $line->serialId === null ? null : Serial::query()->findOrFail($line->serialId);
            if ($serial === null || ! Certificate::query()
                ->where('trackable_type', $serial->getMorphClass())
                ->where('trackable_id', $serial->getKey())
                ->where('type', $type)
                ->where(fn(Builder $q) => $q->whereNull('issued_at')->orWhereDate('issued_at', '<=', $trxDate->toDateString()))
                ->where(fn(Builder $q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $trxDate->toDateString()))
                ->exists()) {
                throw new \DomainException("Serial requires a valid {$type} certificate for issue.");
            }
        }
        if ($batch === null) {
            return;
        }
        if (in_array($batch->status, ['recalled', 'blocked'], true)) {
            throw new \DomainException('Batch is not available for issue.');
        }
        if ($batch->expires_at !== null && $batch->expires_at->isBefore($trxDate->startOfDay())) {
            throw new \DomainException('Line: expired batch cannot be issued.');
        }
        $this->assertCertificates($batch, $this->requiredCertificates($tracking), $trxDate);
    }

    /** @param Builder<CostLayer> $query
     *  @return Builder<CostLayer>
     */
    public function prepareIssueLayers(Builder $query, Item $item, CarbonInterface $trxDate): Builder
    {
        $tracking = (array) ($item->tracking ?? []);
        $requiresBatch = (bool) ($tracking['batch_required_on_receipt'] ?? false);
        $requiresExpiry = (bool) ($tracking['expiry_required_on_receipt'] ?? false);
        $certificates = $this->requiredCertificates($tracking);

        $query->leftJoin('inv_batches as eligible_batch', 'eligible_batch.id', '=', 'inv_cost_layers.batch_id')
            ->select('inv_cost_layers.*')
            ->where(function (Builder $eligible) use ($requiresBatch, $requiresExpiry, $trxDate): void {
                if (! $requiresBatch) {
                    $eligible->whereNull('inv_cost_layers.batch_id');
                }
                $method = $requiresBatch ? 'where' : 'orWhere';
                $eligible->{$method}(function (Builder $batch) use ($requiresExpiry, $trxDate): void {
                    $batch->whereNotNull('inv_cost_layers.batch_id')
                        ->where('eligible_batch.status', 'available')
                        ->where(function (Builder $expiry) use ($requiresExpiry, $trxDate): void {
                            if (! $requiresExpiry) {
                                $expiry->whereNull('eligible_batch.expires_at');
                            }
                            $method = $requiresExpiry ? 'whereDate' : 'orWhereDate';
                            $expiry->{$method}('eligible_batch.expires_at', '>=', $trxDate->toDateString());
                        });
                });
            });

        foreach ($certificates as $type) {
            $query->whereExists(function ($certificate) use ($type, $trxDate): void {
                $certificate->selectRaw('1')
                    ->from('inv_certificates')
                    ->whereColumn('inv_certificates.trackable_id', 'inv_cost_layers.batch_id')
                    ->where('inv_certificates.trackable_type', (new Batch())->getMorphClass())
                    ->where('inv_certificates.type', $type)
                    ->where(function ($validity) use ($trxDate): void {
                        $validity->whereNull('inv_certificates.expires_at')
                            ->orWhereDate('inv_certificates.expires_at', '>=', $trxDate->toDateString());
                    });
            });
        }

        if ($item->costing_method === 'fefo') {
            $query->orderByRaw('CASE WHEN eligible_batch.expires_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('eligible_batch.expires_at')
                ->orderBy('inv_cost_layers.received_at')
                ->orderBy('inv_cost_layers.id');
        } else {
            $query->orderBy('inv_cost_layers.received_at')->orderBy('inv_cost_layers.id');
        }

        return $query;
    }

    /** @param array<string, mixed> $tracking
     *  @return list<string>
     */
    private function requiredCertificates(array $tracking): array
    {
        return array_values(array_filter(
            (array) ($tracking['required_batch_certificates_on_issue'] ?? []),
            fn(mixed $type): bool => is_string($type) && $type !== '',
        ));
    }

    /** @param list<string> $types */
    private function assertCertificates(Batch $batch, array $types, CarbonInterface $trxDate): void
    {
        foreach ($types as $type) {
            $valid = Certificate::query()
                ->where('trackable_type', $batch->getMorphClass())
                ->where('trackable_id', $batch->getKey())
                ->where('type', $type)
                ->where(fn(Builder $query) => $query->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', $trxDate->toDateString()))
                ->exists();
            if (! $valid) {
                throw new \DomainException("Batch requires a valid {$type} certificate for issue.");
            }
        }
    }
}
