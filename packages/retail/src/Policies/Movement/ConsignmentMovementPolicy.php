<?php

namespace ESolution\InventoryRetail\Policies\Movement;

use ESolution\Inventory\Contracts\OwnershipNeutralMovementPolicy;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\InventoryRetail\Services\ConsignmentTermsService;

final class ConsignmentMovementPolicy implements OwnershipNeutralMovementPolicy
{
    public function __construct(private readonly ConsignmentTermsService $terms) {}

    public function name(): string
    {
        return 'consignment';
    }

    public function validate(DocumentLine $line, string $direction): void
    {
        if ($this->terms->resolve(
            (int) $line->item_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        ) === null) {
            throw new \DomainException('Consignment movement requires active supplier terms for the resolved scope.');
        }
    }
}
