<?php

namespace ESolution\InventoryFood\Services;

use ESolution\Inventory\Models\Item;
use ESolution\InventoryFood\DTO\RecipeComponentData;
use ESolution\InventoryFood\Models\Recipe;
use ESolution\InventoryFood\Models\RecipeComponent;
use ESolution\InventoryFood\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

final class RecipeService
{
    public function create(string $code, string $name, int $outputItemId): Recipe
    {
        if ($code === '' || $name === '') {
            throw new \InvalidArgumentException('Recipe requires a code and name.');
        }
        $this->assertItemType($outputItemId, 'allowed_output_item_types', 'output');

        return Recipe::query()->create([
            'code' => $code,
            'name' => $name,
            'output_item_id' => $outputItemId,
        ]);
    }

    /** @param list<RecipeComponentData> $components */
    public function createVersion(
        int $recipeId,
        float $outputQty,
        array $components,
        ?string $effectiveFrom = null,
        ?string $effectiveTo = null,
    ): RecipeVersion {
        if ($outputQty <= 0 || $components === []) {
            throw new \InvalidArgumentException('A Recipe version requires positive output and at least one component.');
        }

        return DB::transaction(function () use ($recipeId, $outputQty, $components, $effectiveFrom, $effectiveTo): RecipeVersion {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            $seen = [];
            foreach ($components as $component) {
                if (! $component instanceof RecipeComponentData) {
                    throw new \InvalidArgumentException('Recipe components must use RecipeComponentData.');
                }
                if ($component->itemId === (int) $recipe->output_item_id) {
                    throw new \DomainException('A Recipe cannot directly consume its own output item.');
                }
                if (isset($seen[$component->itemId])) {
                    throw new \DomainException('A Recipe version cannot contain the same component Item twice.');
                }
                $seen[$component->itemId] = true;
                $this->assertItemType($component->itemId, 'allowed_component_item_types', 'component');
            }

            $version = RecipeVersion::query()->create([
                'recipe_id' => $recipe->getKey(),
                'version' => (int) $recipe->versions()->max('version') + 1,
                'output_qty' => $outputQty,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);
            foreach ($components as $component) {
                RecipeComponent::query()->create([
                    'recipe_version_id' => $version->getKey(),
                    'item_id' => $component->itemId,
                    'uom_id' => $component->uomId,
                    'qty' => $component->qty,
                    'sequence' => $component->sequence,
                ]);
            }

            return $version->load('recipe', 'components');
        });
    }

    public function publish(int $versionId): RecipeVersion
    {
        return DB::transaction(function () use ($versionId): RecipeVersion {
            $version = RecipeVersion::query()->lockForUpdate()->findOrFail($versionId);
            if ($version->status === 'published') {
                return $version->load('recipe', 'components');
            }
            if ($version->status !== 'draft' || ! $version->components()->exists()) {
                throw new \DomainException('Only a populated draft Recipe version can be published.');
            }
            $this->assertVersionItems($version->load('recipe', 'components'));
            $version->status = 'published';
            $version->published_at = now();
            $version->save();

            return $version->load('recipe', 'components');
        });
    }

    public function assertVersionItems(RecipeVersion $version): void
    {
        $recipe = Recipe::query()->findOrFail($version->recipe_id);
        $this->assertItemType((int) $recipe->output_item_id, 'allowed_output_item_types', 'output');
        foreach (RecipeComponent::query()->where('recipe_version_id', $version->getKey())->get() as $component) {
            $this->assertItemType((int) $component->item_id, 'allowed_component_item_types', 'component');
        }
    }

    private function assertItemType(int $itemId, string $configKey, string $role): Item
    {
        $item = Item::query()->findOrFail($itemId);
        $allowed = (array) config("inventory-food.{$configKey}", ['stock']);
        if (! $item->is_active || ! in_array($item->item_type, $allowed, true)) {
            throw new \DomainException("Food {$role} Item Type '{$item->item_type}' is not allowed or the Item is inactive.");
        }

        return $item;
    }
}
