<?php

namespace ESolution\InventoryHealthcare\Services;

use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\Document;
use ESolution\InventoryHealthcare\Models\Recall;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RecallService
{
    public function recall(string $recallNo, int $batchId, string $reason): Recall
    {
        if ($recallNo === '' || $reason === '') {
            throw new \InvalidArgumentException('Recall requires a number and reason.');
        }

        return DB::transaction(function () use ($recallNo, $batchId, $reason): Recall {
            $batch = Batch::query()->lockForUpdate()->findOrFail($batchId);
            $existing = Recall::query()->where('recall_no', $recallNo)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->batch_id !== $batchId || $existing->reason !== $reason) {
                    throw new \DomainException('Recall number was reused with a different payload.');
                }

                return $existing;
            }
            if (Recall::query()->where('batch_id', $batchId)->where('status', 'active')->exists()) {
                throw new \DomainException('Batch already has an active recall.');
            }

            $recall = Recall::query()->create([
                'recall_no' => $recallNo,
                'batch_id' => $batchId,
                'reason' => $reason,
                'recalled_at' => now(),
            ]);
            $batch->status = 'recalled';
            $batch->save();

            return $recall;
        });
    }

    public function release(int $recallId): Recall
    {
        return DB::transaction(function () use ($recallId): Recall {
            $recall = Recall::query()->lockForUpdate()->findOrFail($recallId);
            if ($recall->status === 'released') {
                return $recall;
            }
            $batch = Batch::query()->lockForUpdate()->findOrFail($recall->batch_id);
            $recall->status = 'released';
            $recall->released_at = now();
            $recall->save();
            if (! Recall::query()->where('batch_id', $batch->getKey())->where('status', 'active')->exists()) {
                $batch->status = 'available';
                $batch->save();
            }

            return $recall;
        });
    }

    /** @return Collection<int, Document> */
    public function forwardTrace(int $recallId): Collection
    {
        $recall = Recall::query()->findOrFail($recallId);
        $documentIds = DB::table('inv_stock_ledgers as ledger')
            ->join('inv_cost_layers as layer', 'layer.id', '=', 'ledger.cost_layer_id')
            ->join('inv_document_lines as line', 'line.id', '=', 'ledger.document_line_id')
            ->where('layer.batch_id', $recall->batch_id)
            ->where('ledger.direction', 'out')
            ->distinct()
            ->pluck('line.document_id');

        return Document::query()->whereKey($documentIds)->orderBy('id')->get();
    }
}
