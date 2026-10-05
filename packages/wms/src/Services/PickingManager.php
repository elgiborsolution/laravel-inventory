<?php

namespace ESolution\InventoryWms\Services;

use ESolution\InventoryWms\Contracts\PickingStrategy;
use ESolution\InventoryWms\DTO\PickingRequest;
use Illuminate\Contracts\Container\Container;

final class PickingManager
{
    /** @param array<string, class-string<PickingStrategy>> $strategies */
    public function __construct(
        private readonly Container $container,
        private readonly array $strategies,
    ) {}

    public function suggest(PickingRequest $request, ?string $strategy = null): array
    {
        $strategy ??= (string) config('inventory-wms.picking.default_strategy', 'fifo');
        $class = $this->strategies[$strategy] ?? null;
        if ($class === null) {
            throw new \DomainException("Unknown picking strategy '{$strategy}'.");
        }

        return $this->container->make($class)->suggest($request);
    }
}
