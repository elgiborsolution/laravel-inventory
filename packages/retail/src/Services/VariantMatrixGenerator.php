<?php

namespace ESolution\InventoryRetail\Services;

use ESolution\Inventory\Models\Item;
use ESolution\InventoryRetail\Models\ItemVariantLink;
use ESolution\InventoryRetail\Models\ProductFamily;
use ESolution\InventoryRetail\Models\VariantAxis;
use ESolution\InventoryRetail\Models\VariantAxisValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class VariantMatrixGenerator
{
    /**
     * @param null|list<list<int|VariantAxisValue>> $axisValueSets
     * @return Collection<int, Item>
     */
    public function generate(ProductFamily $family, ?array $axisValueSets = null): Collection
    {
        if ($family->is_active === false) {
            throw new \DomainException('Variants cannot be generated for an inactive Product Family.');
        }

        $sets = $this->normalizeSets($family, $axisValueSets);
        $specifications = $this->specifications($family, $sets);

        return DB::transaction(function () use ($family, $specifications): Collection {
            $skus = array_column($specifications, 'sku');
            $existing = Item::query()->whereIn('sku', $skus)->get()->keyBy('sku');
            $rows = [];
            $timestamp = now();

            foreach ($specifications as $specification) {
                $item = $existing->get($specification['sku']);
                if ($item !== null) {
                    $link = ItemVariantLink::query()->where('item_id', $item->getKey())->first();
                    if ($link === null
                        || (int) $link->product_family_id !== (int) $family->getKey()
                        || $link->combination_key !== $specification['combination_key']) {
                        throw new \DomainException("Variant SKU '{$specification['sku']}' is already used outside this combination.");
                    }
                    continue;
                }

                $rows[] = [
                    'sku' => $specification['sku'],
                    'name' => $specification['name'],
                    'item_type' => 'stock',
                    'item_category_id' => $family->item_category_id,
                    'base_uom_id' => $family->base_uom_id,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach (array_chunk($rows, max(1, (int) config('inventory-retail.variant_matrix.insert_chunk_size', 100))) as $chunk) {
                Item::query()->insert($chunk);
            }

            $items = Item::query()->whereIn('sku', $skus)->get()->keyBy('sku');
            foreach ($specifications as $specification) {
                $item = $items->get($specification['sku'])
                    ?? throw new \RuntimeException('Generated Retail variant could not be reloaded.');
                $link = ItemVariantLink::query()->firstOrCreate([
                    'product_family_id' => $family->getKey(),
                    'combination_key' => $specification['combination_key'],
                ], [
                    'item_id' => $item->getKey(),
                ]);
                if ((int) $link->item_id !== (int) $item->getKey()) {
                    throw new \DomainException('Variant combination is already linked to a different Item.');
                }
                $link->axisValues()->syncWithoutDetaching($specification['value_ids']);
            }

            return collect($specifications)
                ->map(function (array $specification) use ($items): Item {
                    $item = $items->get($specification['sku']);
                    if (! $item instanceof Item) {
                        throw new \RuntimeException('Generated Retail variant could not be returned.');
                    }

                    return $item;
                })
                ->values();
        }, 3);
    }

    /**
     * @param null|list<list<int|VariantAxisValue>> $provided
     * @return list<list<VariantAxisValue>>
     */
    private function normalizeSets(ProductFamily $family, ?array $provided): array
    {
        $axes = VariantAxis::query()
            ->where('product_family_id', $family->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($axes->isEmpty()) {
            throw new \DomainException('A Product Family requires at least one Variant Axis.');
        }

        if ($provided === null) {
            $sets = [];
            foreach ($axes as $axis) {
                $values = array_values(VariantAxisValue::query()
                    ->where('variant_axis_id', $axis->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->all());
                if ($values === []) {
                    throw new \DomainException("Variant Axis '{$axis->name}' has no values.");
                }
                $sets[] = $values;
            }

            return $sets;
        }

        $valuesByAxis = [];
        foreach ($provided as $set) {
            if ($set === []) {
                throw new \DomainException('Variant Axis value sets cannot be empty.');
            }
            $ids = array_map(
                static fn(int|VariantAxisValue $value): int => $value instanceof VariantAxisValue
                    ? (int) $value->getKey()
                    : $value,
                $set,
            );
            $values = VariantAxisValue::query()->whereIn('id', $ids)->get();
            if ($values->count() !== count(array_unique($ids)) || $values->pluck('variant_axis_id')->unique()->count() !== 1) {
                throw new \DomainException('Each matrix set must contain unique values from exactly one Variant Axis.');
            }
            $valuesByAxis[(int) $values->firstOrFail()->variant_axis_id] = array_values($values->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])->values()->all());
        }

        $expectedAxisIds = $axes->modelKeys();
        if (array_diff($expectedAxisIds, array_keys($valuesByAxis)) !== []
            || array_diff(array_keys($valuesByAxis), $expectedAxisIds) !== []) {
            throw new \DomainException('Matrix input must provide one value set for every Product Family axis.');
        }

        $sets = [];
        foreach ($axes as $axis) {
            $sets[] = $valuesByAxis[(int) $axis->getKey()];
        }

        return $sets;
    }

    /**
     * @param list<list<VariantAxisValue>> $sets
     * @return list<array{sku:string,name:string,combination_key:string,value_ids:list<int>}>
     */
    private function specifications(ProductFamily $family, array $sets): array
    {
        $combinations = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($set as $value) {
                    $next[] = [...$combination, $value];
                }
            }
            $combinations = $next;
        }

        $specifications = [];
        $seen = [];
        foreach ($combinations as $combination) {
            $codes = array_map(fn(VariantAxisValue $value): string => $this->skuPart($value->code), $combination);
            $sku = $this->skuPart($family->base_sku) . '-' . implode('-', $codes);
            if (strlen($sku) > 96 || isset($seen[$sku])) {
                throw new \DomainException("Generated variant SKU '{$sku}' is invalid or duplicated.");
            }
            $seen[$sku] = true;
            $ids = array_map(static fn(VariantAxisValue $value): int => (int) $value->getKey(), $combination);
            sort($ids);
            $specifications[] = [
                'sku' => $sku,
                'name' => $family->base_name . ' (' . implode(', ', array_column($combination, 'value')) . ')',
                'combination_key' => hash('sha256', implode(':', $ids)),
                'value_ids' => $ids,
            ];
        }

        return $specifications;
    }

    private function skuPart(string $value): string
    {
        $normalized = trim((string) preg_replace('/[^A-Z0-9]+/', '-', strtoupper($value)), '-');
        if ($normalized === '') {
            throw new \DomainException('Variant SKU components must contain letters or numbers.');
        }

        return $normalized;
    }
}
