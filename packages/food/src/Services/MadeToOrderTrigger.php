<?php

namespace ESolution\InventoryFood\Services;

use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\InventoryFood\DTO\RecipeBatchData;
use ESolution\InventoryFood\Models\Recipe;
use ESolution\InventoryFood\Models\RecipeBatch;
use ESolution\InventoryFood\Models\RecipeVersion;
use Illuminate\Database\Eloquent\Collection;

final class MadeToOrderTrigger
{
    public function __construct(private readonly RecipeBatchService $batches) {}

    /** @return Collection<int, RecipeBatch> */
    public function handle(Document $document, string $toStatus): Collection
    {
        $result = new Collection();
        if ($toStatus !== (string) config('inventory-food.mto.trigger_status', 'posted')) {
            return $result;
        }

        foreach (DocumentLine::query()
            ->where('document_id', $document->getKey())
            ->orderBy('line_no')
            ->get() as $line) {
            $versionId = (int) (($line->meta ?? [])['recipe_version_id'] ?? 0);
            if ($versionId <= 0) {
                continue;
            }
            $version = RecipeVersion::query()->findOrFail($versionId);
            $recipe = Recipe::query()->findOrFail($version->recipe_id);
            if ((int) $recipe->output_item_id !== (int) $line->item_id) {
                throw new \DomainException('MTO Recipe version output does not match the source document line Item.');
            }

            $result->push($this->batches->create(new RecipeBatchData(
                batchNo: 'MTO-' . $document->getKey() . '-' . $line->line_no,
                recipeVersionId: $versionId,
                organizationId: (int) $document->organization_id,
                warehouseId: (int) $line->warehouse_id,
                plannedQty: (float) $line->qty + (float) $line->qty_bonus,
                mode: 'mto',
                sourceDocumentId: (int) $document->getKey(),
                sourceLineId: (int) $line->getKey(),
                meta: ['source_external_id' => $document->external_id],
            )));
        }

        return $result;
    }
}
