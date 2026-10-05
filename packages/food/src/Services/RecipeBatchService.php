<?php

namespace ESolution\InventoryFood\Services;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryFood\DTO\RecipeBatchData;
use ESolution\InventoryFood\Models\Recipe;
use ESolution\InventoryFood\Models\RecipeBatch;
use ESolution\InventoryFood\Models\RecipeComponent;
use ESolution\InventoryFood\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

final class RecipeBatchService
{
    public function __construct(
        private readonly InventoryManager $inventory,
        private readonly FoodAccountingGuard $accounting,
        private readonly RecipeService $recipes,
    ) {}

    public function create(RecipeBatchData $data): RecipeBatch
    {
        return DB::transaction(function () use ($data): RecipeBatch {
            $version = RecipeVersion::query()->with('recipe', 'components')->findOrFail($data->recipeVersionId);
            if ($version->status !== 'published') {
                throw new \DomainException('RecipeBatches require a published Recipe version.');
            }
            $this->recipes->assertVersionItems($version);

            if ($data->sourceDocumentId !== null && $data->sourceLineId !== null) {
                $sourceMatch = RecipeBatch::query()
                    ->where('source_document_id', $data->sourceDocumentId)
                    ->where('source_line_id', $data->sourceLineId)
                    ->lockForUpdate()
                    ->first();
                if ($sourceMatch !== null) {
                    return $this->assertSamePayload($sourceMatch, $data);
                }
            }

            $existing = RecipeBatch::query()->where('batch_no', $data->batchNo)->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->assertSamePayload($existing, $data);
            }

            return RecipeBatch::query()->create([
                'batch_no' => $data->batchNo,
                'recipe_version_id' => $data->recipeVersionId,
                'organization_id' => $data->organizationId,
                'warehouse_id' => $data->warehouseId,
                'planned_qty' => $data->plannedQty,
                'mode' => $data->mode,
                'source_document_id' => $data->sourceDocumentId,
                'source_line_id' => $data->sourceLineId,
                'output_batch_id' => $data->outputBatchId,
                'meta' => $data->meta,
            ])->load('recipeVersion.recipe', 'recipeVersion.components');
        });
    }

    /**
     * @param array<int, float> $actualComponentQtyByItem
     * @param array<int, int>   $componentLocationByItem
     * @param array<int, int>   $componentBatchByItem
     */
    public function complete(
        int $recipeBatchId,
        float $actualOutputQty,
        array $actualComponentQtyByItem = [],
        array $componentLocationByItem = [],
        array $componentBatchByItem = [],
        ?int $outputLocationId = null,
        ?string $trxDate = null,
    ): RecipeBatch {
        if ($actualOutputQty <= 0) {
            throw new \InvalidArgumentException('Actual Recipe output must be positive.');
        }

        return DB::transaction(function () use (
            $recipeBatchId,
            $actualOutputQty,
            $actualComponentQtyByItem,
            $componentLocationByItem,
            $componentBatchByItem,
            $outputLocationId,
            $trxDate,
        ): RecipeBatch {
            $batch = RecipeBatch::query()->lockForUpdate()->findOrFail($recipeBatchId);
            if ($batch->status === 'completed') {
                return $batch->load('recipeVersion.recipe', 'recipeVersion.components');
            }
            if ($batch->status !== 'planned') {
                throw new \DomainException('Only a planned RecipeBatch can be completed.');
            }
            $this->accounting->assertDisabled();

            $version = RecipeVersion::query()->with('recipe', 'components')->findOrFail($batch->recipe_version_id);
            if ($version->status !== 'published') {
                throw new \DomainException('RecipeBatch completion requires a published Recipe version.');
            }
            $this->recipes->assertVersionItems($version);
            $components = RecipeComponent::query()
                ->where('recipe_version_id', $version->getKey())
                ->orderBy('sequence')
                ->orderBy('id')
                ->get();
            $componentIds = $components->pluck('item_id')->map(fn($id): int => (int) $id)->all();
            foreach (array_keys($actualComponentQtyByItem) as $itemId) {
                if (! in_array((int) $itemId, $componentIds, true)) {
                    throw new \DomainException("Actual quantity references Item {$itemId}, which is not in the Recipe version.");
                }
            }

            $lines = [];
            foreach ($components as $component) {
                $itemId = (int) $component->item_id;
                $expected = (float) $component->qty * ((float) $batch->planned_qty / (float) $version->output_qty);
                $actual = array_key_exists($itemId, $actualComponentQtyByItem)
                    ? (float) $actualComponentQtyByItem[$itemId]
                    : $expected;
                if ($actual < 0) {
                    throw new \DomainException('Actual Recipe component quantity cannot be negative.');
                }
                if ($actual > 0) {
                    $lines[] = new LineData(
                        $itemId,
                        (int) $component->uom_id,
                        (int) $batch->warehouse_id,
                        $actual,
                        $componentLocationByItem[$itemId] ?? null,
                        batchId: $componentBatchByItem[$itemId] ?? null,
                    );
                }
            }
            if ($lines === []) {
                throw new \DomainException('RecipeBatch must consume at least one component.');
            }

            $date = $trxDate ?? now()->toDateString();
            $context = [
                'recipe_batch_id' => $batch->getKey(),
                'recipe_batch_no' => $batch->batch_no,
                'recipe_version_id' => $version->getKey(),
                'mode' => $batch->mode,
                'source_document_id' => $batch->source_document_id,
                'source_line_id' => $batch->source_line_id,
            ];
            $consumption = $this->inventory->post(new DocumentData(
                type: 'recipe_consumption',
                organizationId: (int) $batch->organization_id,
                trxDate: $date,
                externalId: $batch->batch_no . ':consumption',
                sourceType: RecipeBatch::class,
                sourceId: (string) $batch->getKey(),
                lines: $lines,
                meta: $context,
            ));
            $actualCost = (float) StockLedger::query()
                ->whereIn('document_line_id', $consumption->lines->pluck('id'))
                ->sum('amount');
            $unitCost = $actualCost / $actualOutputQty;
            $recipe = Recipe::query()->findOrFail($version->recipe_id);
            $outputItem = Item::query()->findOrFail($recipe->output_item_id);
            $receipt = $this->inventory->post(new DocumentData(
                type: 'recipe_receipt',
                organizationId: (int) $batch->organization_id,
                trxDate: $date,
                externalId: $batch->batch_no . ':receipt',
                sourceType: RecipeBatch::class,
                sourceId: (string) $batch->getKey(),
                lines: [new LineData(
                    (int) $outputItem->getKey(),
                    (int) $outputItem->base_uom_id,
                    (int) $batch->warehouse_id,
                    $actualOutputQty,
                    $outputLocationId,
                    unitCost: $unitCost,
                    batchId: $batch->output_batch_id === null ? null : (int) $batch->output_batch_id,
                )],
                meta: $context,
            ));

            $batch->forceFill([
                'status' => 'completed',
                'actual_output_qty' => $actualOutputQty,
                'actual_component_cost' => $actualCost,
                'output_unit_cost' => $unitCost,
                'consumption_document_id' => $consumption->getKey(),
                'receipt_document_id' => $receipt->getKey(),
                'completed_at' => now(),
            ])->save();

            return $batch->refresh()->load('recipeVersion.recipe', 'recipeVersion.components');
        }, 3);
    }

    private function assertSamePayload(RecipeBatch $batch, RecipeBatchData $data): RecipeBatch
    {
        $same = (int) $batch->recipe_version_id === $data->recipeVersionId
            && (int) $batch->organization_id === $data->organizationId
            && (int) $batch->warehouse_id === $data->warehouseId
            && (float) $batch->planned_qty === $data->plannedQty
            && $batch->mode === $data->mode
            && $batch->source_document_id === $data->sourceDocumentId
            && $batch->source_line_id === $data->sourceLineId
            && $batch->output_batch_id === $data->outputBatchId;
        if (! $same) {
            throw new \DomainException('RecipeBatch identity was reused with a different payload.');
        }

        return $batch->load('recipeVersion.recipe', 'recipeVersion.components');
    }
}
