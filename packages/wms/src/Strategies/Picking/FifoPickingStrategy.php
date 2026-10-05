<?php

namespace ESolution\InventoryWms\Strategies\Picking;

use ESolution\InventoryWms\Contracts\PickingStrategy;
use ESolution\InventoryWms\DTO\PickingRequest;
use ESolution\InventoryWms\Services\PickAllocator;

final class FifoPickingStrategy implements PickingStrategy
{
    public function __construct(private readonly PickAllocator $allocator) {}

    public function suggest(PickingRequest $request): array
    {
        return $this->allocator->allocate($request, 'fifo');
    }
}
