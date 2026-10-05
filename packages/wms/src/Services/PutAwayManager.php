<?php

namespace ESolution\InventoryWms\Services;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\PutAwayRule;
use Illuminate\Contracts\Container\Container;

final class PutAwayManager
{
    /** @param array<string, class-string<PutAwayStrategy>> $strategies */
    public function __construct(
        private readonly Container $container,
        private readonly array $strategies,
    ) {}

    public function suggest(PutAwayRequest $request, ?string $strategy = null): StorageLocation
    {
        $strategy ??= $this->resolveStrategy($request);
        $class = $this->strategies[$strategy] ?? null;
        if ($class === null) {
            throw new \DomainException("Unknown put-away strategy '{$strategy}'.");
        }

        return $this->container->make($class)->suggest($request);
    }

    private function resolveStrategy(PutAwayRequest $request): string
    {
        return (string) (PutAwayRule::query()
            ->where('warehouse_id', $request->warehouseId)
            ->where('is_active', true)
            ->where(fn($query) => $query->where('item_id', $request->itemId)->orWhereNull('item_id'))
            ->orderByRaw('CASE WHEN item_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority')
            ->orderBy('id')
            ->value('strategy') ?? config('inventory-wms.put_away.default_strategy', 'dynamic'));
    }
}
