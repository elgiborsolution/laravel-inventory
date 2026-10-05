<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\MovementPolicy;
use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\PolicyOverride;
use Illuminate\Contracts\Container\Container;

final class MovementPolicyManager implements MovementPolicyRegistry
{
    /** @var array<string, class-string<MovementPolicy>> */
    private array $policies = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $inventoryModel, string $policyClass): void
    {
        if ($inventoryModel === '' || ! is_a($policyClass, MovementPolicy::class, true)) {
            throw new \InvalidArgumentException('Movement policy registration is invalid.');
        }

        $this->policies[$inventoryModel] = $policyClass;
    }

    public function resolve(DocumentLine $line): ?MovementPolicy
    {
        $model = $this->resolvedModel($line);
        if ($model === 'standard') {
            return null;
        }
        if (! isset($this->policies[$model])) {
            throw new \DomainException("No MovementPolicy is registered for inventory model '{$model}'.");
        }

        return $this->container->make($this->policies[$model]);
    }

    public function resolvedModel(DocumentLine $line): string
    {
        if ($line->storage_location_id !== null) {
            $location = PolicyOverride::query()
                ->where('policy_type', 'inventory_model')
                ->where('item_id', $line->item_id)
                ->where('location_id', $line->storage_location_id)
                ->first();
            if ($location !== null) {
                return $this->modelFromValue($location->value);
            }
        }

        $item = PolicyOverride::query()
            ->where('policy_type', 'inventory_model')
            ->where('item_id', $line->item_id)
            ->whereNull('location_id')
            ->first();

        return $item === null
            ? (string) config('inventory.inventory_model.default', 'standard')
            : $this->modelFromValue($item->value);
    }

    private function modelFromValue(mixed $value): string
    {
        $model = is_array($value) ? ($value['model'] ?? null) : $value;
        if (! is_string($model) || $model === '') {
            throw new \DomainException('Inventory model policy override is invalid.');
        }

        return $model;
    }
}
