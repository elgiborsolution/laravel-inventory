<?php

namespace ESolution\InventoryWms\Services;

use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Events\DocumentPosted;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\InventoryWms\DTO\PickingRequest;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\CrossDockRoute;
use ESolution\InventoryWms\Models\Task;

final class TaskOrchestrator
{
    public function __construct(
        private readonly PutAwayManager $putAway,
        private readonly PickingManager $picking,
        private readonly MovementPolicyRegistry $movementPolicies,
    ) {}

    public function handle(DocumentPosted $event): void
    {
        $taskType = config('inventory-wms.task_document_types.' . $event->document->document_type);
        if (! is_string($taskType) || ! in_array($taskType, ['put_away', 'pick'], true)) {
            return;
        }

        foreach ($event->document->lines as $line) {
            $taskType === 'put_away'
                ? $this->createInboundTask($line)
                : $this->createPickingTasks($line);
        }
    }

    private function createInboundTask(DocumentLine $line): void
    {
        if ($this->movementPolicies->resolvedModel($line) === 'cross_dock') {
            $route = CrossDockRoute::query()
                ->where('warehouse_id', $line->warehouse_id)
                ->where('is_active', true)
                ->where(fn($query) => $query->where('item_id', $line->item_id)->orWhereNull('item_id'))
                ->orderByRaw('CASE WHEN item_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('priority')
                ->orderBy('id')
                ->firstOrFail();
            $this->storeTask($line, 'cross_dock', (float) $line->qty + (float) $line->qty_bonus, 0, $line->storage_location_id, (int) $route->staging_location_id);

            return;
        }

        try {
            $destination = $this->putAway->suggest(new PutAwayRequest(
                (int) $line->item_id,
                (int) $line->warehouse_id,
                (float) $line->qty + (float) $line->qty_bonus,
                $line->storage_location_id === null ? null : (int) $line->storage_location_id,
                deterministicKey: 'document-line:' . $line->getKey(),
            ));
            $this->storeTask($line, 'put_away', (float) $line->qty + (float) $line->qty_bonus, 0, $line->storage_location_id, (int) $destination->getKey());
        } catch (\DomainException $exception) {
            $this->storeTask($line, 'put_away', (float) $line->qty + (float) $line->qty_bonus, 0, $line->storage_location_id, meta: [
                'suggestion_status' => 'pending',
                'suggestion_error' => $exception->getMessage(),
            ]);
        }
    }

    private function createPickingTasks(DocumentLine $line): void
    {
        if ($line->storage_location_id !== null) {
            $this->storeTask($line, 'pick', (float) $line->qty + (float) $line->qty_bonus, 0, (int) $line->storage_location_id);

            return;
        }

        try {
            $suggestions = $this->picking->suggest(new PickingRequest(
                (int) $line->item_id,
                (int) $line->warehouse_id,
                (float) $line->qty + (float) $line->qty_bonus,
            ));
        } catch (\DomainException $exception) {
            $this->storeTask($line, 'pick', (float) $line->qty + (float) $line->qty_bonus, 0, meta: [
                'suggestion_status' => 'pending',
                'suggestion_error' => $exception->getMessage(),
            ]);

            return;
        }
        foreach ($suggestions as $index => $suggestion) {
            $this->storeTask($line, 'pick', $suggestion->qty, $index, $suggestion->locationId, meta: ['batch_id' => $suggestion->batchId]);
        }
    }

    private function storeTask(
        DocumentLine $line,
        string $type,
        float $qty,
        int $sequence,
        ?int $fromLocationId = null,
        ?int $toLocationId = null,
        array $meta = [],
    ): Task {
        $key = "document-line:{$line->getKey()}:{$type}:{$sequence}";

        return Task::query()->firstOrCreate(['idempotency_key' => $key], [
            'type' => $type,
            'warehouse_id' => $line->warehouse_id,
            'document_id' => $line->document_id,
            'document_line_id' => $line->getKey(),
            'item_id' => $line->item_id,
            'qty' => $qty,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'meta' => $meta,
        ]);
    }
}
