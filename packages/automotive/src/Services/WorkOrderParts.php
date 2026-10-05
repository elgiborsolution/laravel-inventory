<?php

namespace ESolution\InventoryAutomotive\Services;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Services\InventoryManager;

final class WorkOrderParts
{
    public function __construct(private readonly InventoryManager $inventory) {}
    /** @param list<LineData> $lines */
    public function issue(string $externalId, int $organizationId, string $trxDate, string $workOrderType, string $workOrderId, string $vehicleType, string $vehicleId, array $lines): Document
    {
        if ($externalId === '' || $workOrderType === '' || $workOrderId === '' || $vehicleType === '' || $vehicleId === '') {
            throw new \InvalidArgumentException('Work Order and vehicle references and external ID are required.');
        }
        return $this->inventory->post(new DocumentData(
            type: 'work_order_parts_issue',
            organizationId: $organizationId,
            trxDate: $trxDate,
            externalId: $externalId,
            sourceType: $workOrderType,
            sourceId: $workOrderId,
            partyType: $vehicleType,
            partyId: $vehicleId,
            lines: $lines,
        ));
    }
}
