<?php

namespace ESolution\InventoryManufacturing\Services;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryManufacturing\DTO\ProductionOrderData;
use ESolution\InventoryManufacturing\Models\Bom;
use ESolution\InventoryManufacturing\Models\BomComponent;
use ESolution\InventoryManufacturing\Models\BomVersion;
use ESolution\InventoryManufacturing\Models\ProductionOrder;
use ESolution\InventoryManufacturing\Models\ProductionVariance;
use Illuminate\Support\Facades\DB;

final class ProductionOrderService
{
    public function __construct(
        private readonly InventoryManager $inventory,
        private readonly ManufacturingAccountingGuard $accounting,
        private readonly BomService $boms,
    ) {}

    public function create(ProductionOrderData $data): ProductionOrder
    {
        $mode = strtolower($data->sourceMode);
        if (! in_array($mode, (array) config('inventory-manufacturing.source_modes', []), true)) {
            throw new \DomainException("Unsupported production source mode '{$mode}'.");
        }
        if ($mode !== 'mts' && ($data->sourceType === null || $data->sourceId === null)) {
            throw new \DomainException(strtoupper($mode) . ' Production Orders require a source reference.');
        }

        return DB::transaction(function () use ($data, $mode): ProductionOrder {
            $version = BomVersion::query()->with('bom', 'components')->findOrFail($data->bomVersionId);
            if ($version->status !== 'active') {
                throw new \DomainException('Production Orders require an active BOM version.');
            }
            $this->boms->assertVersionItems($version);
            $this->assertParentChain($data->parentOrderId, $version);

            $existing = ProductionOrder::query()->where('order_no', $data->orderNo)->lockForUpdate()->first();
            if ($existing !== null) {
                $samePayload = (int) $existing->bom_version_id === $data->bomVersionId
                    && (int) $existing->organization_id === $data->organizationId
                    && (int) $existing->warehouse_id === $data->warehouseId
                    && (float) $existing->planned_qty === $data->plannedQty
                    && $existing->source_mode === $mode
                    && $existing->source_type === $data->sourceType
                    && $existing->source_id === $data->sourceId
                    && $existing->parent_order_id === $data->parentOrderId;
                if (! $samePayload) {
                    throw new \DomainException('Production Order number was reused with a different payload.');
                }

                return $existing->load('bomVersion.bom', 'bomVersion.components');
            }

            return ProductionOrder::query()->create([
                'order_no' => $data->orderNo,
                'bom_version_id' => $data->bomVersionId,
                'organization_id' => $data->organizationId,
                'warehouse_id' => $data->warehouseId,
                'planned_qty' => $data->plannedQty,
                'source_mode' => $mode,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'parent_order_id' => $data->parentOrderId,
                'meta' => $data->meta,
            ])->load('bomVersion.bom', 'bomVersion.components');
        });
    }

    /**
     * @param array<int, float> $actualComponentQtyByItem
     * @param array<int, int>   $componentLocationByItem
     */
    public function complete(
        int $orderId,
        float $actualOutputQty,
        array $actualComponentQtyByItem = [],
        array $componentLocationByItem = [],
        ?int $outputLocationId = null,
        ?string $trxDate = null,
    ): ProductionOrder {
        if ($actualOutputQty <= 0) {
            throw new \InvalidArgumentException('Actual production output must be positive.');
        }
        return DB::transaction(function () use (
            $orderId,
            $actualOutputQty,
            $actualComponentQtyByItem,
            $componentLocationByItem,
            $outputLocationId,
            $trxDate,
        ): ProductionOrder {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status === 'completed') {
                return $order->load('bomVersion.bom', 'bomVersion.components', 'variances');
            }
            if ($order->status !== 'planned') {
                throw new \DomainException('Only a planned Production Order can be completed.');
            }
            $this->accounting->assertDisabled();

            $version = BomVersion::query()->with('bom', 'components')->findOrFail($order->bom_version_id);
            $this->boms->assertVersionItems($version);
            $this->assertParentChain($order->parent_order_id, $version, true);
            $components = BomComponent::query()
                ->where('bom_version_id', $version->getKey())
                ->orderBy('sequence')
                ->orderBy('id')
                ->get();
            $componentItemIds = $components->pluck('item_id')->map(fn($id): int => (int) $id)->all();
            foreach (array_keys($actualComponentQtyByItem) as $itemId) {
                if (! in_array((int) $itemId, $componentItemIds, true)) {
                    throw new \DomainException("Actual quantity references Item {$itemId}, which is not in the BOM version.");
                }
            }

            $componentLines = [];
            $actualQuantities = [];
            foreach ($components as $component) {
                $itemId = (int) $component->item_id;
                $expected = $this->expectedComponentQty($component, $version, (float) $order->planned_qty);
                $actual = array_key_exists($itemId, $actualComponentQtyByItem)
                    ? (float) $actualComponentQtyByItem[$itemId]
                    : $expected;
                if ($actual < 0) {
                    throw new \DomainException('Actual component quantity cannot be negative.');
                }
                $actualQuantities[$itemId] = ['expected' => $expected, 'actual' => $actual];
                if ($actual > 0) {
                    $componentLines[] = new LineData(
                        $itemId,
                        (int) $component->uom_id,
                        (int) $order->warehouse_id,
                        $actual,
                        $componentLocationByItem[$itemId] ?? null,
                    );
                }
            }
            if ($componentLines === []) {
                throw new \DomainException('Production must consume at least one component.');
            }

            $date = $trxDate ?? now()->toDateString();
            $sourceType = ProductionOrder::class;
            $context = [
                'production_order_id' => $order->getKey(),
                'production_order_no' => $order->order_no,
                'bom_version_id' => $version->getKey(),
                'source_mode' => $order->source_mode,
                'business_source_type' => $order->source_type,
                'business_source_id' => $order->source_id,
                'parent_order_id' => $order->parent_order_id,
            ];
            $consumption = $this->inventory->post(new DocumentData(
                type: 'production_consumption',
                organizationId: (int) $order->organization_id,
                trxDate: $date,
                externalId: $order->order_no . ':consumption',
                sourceType: $sourceType,
                sourceId: (string) $order->getKey(),
                lines: $componentLines,
                meta: $context,
            ));
            $actualCost = (float) StockLedger::query()
                ->whereIn('document_line_id', $consumption->lines->pluck('id'))
                ->sum('amount');
            $unitCost = $actualCost / $actualOutputQty;
            $bom = Bom::query()->findOrFail($version->bom_id);
            $outputItem = Item::query()->findOrFail($bom->output_item_id);

            $receipt = $this->inventory->post(new DocumentData(
                type: 'production_receipt',
                organizationId: (int) $order->organization_id,
                trxDate: $date,
                externalId: $order->order_no . ':receipt',
                sourceType: $sourceType,
                sourceId: (string) $order->getKey(),
                lines: [new LineData(
                    (int) $outputItem->getKey(),
                    (int) $outputItem->base_uom_id,
                    (int) $order->warehouse_id,
                    $actualOutputQty,
                    $outputLocationId,
                    unitCost: $unitCost,
                )],
                meta: $context,
            ));

            $this->recordVariances($order, $consumption, $actualQuantities, $actualOutputQty, $unitCost, (int) $outputItem->getKey());
            $order->forceFill([
                'status' => 'completed',
                'actual_output_qty' => $actualOutputQty,
                'actual_component_cost' => $actualCost,
                'output_unit_cost' => $unitCost,
                'consumption_document_id' => $consumption->getKey(),
                'receipt_document_id' => $receipt->getKey(),
                'completed_at' => now(),
            ])->save();

            return $order->refresh()->load('bomVersion.bom', 'bomVersion.components', 'variances');
        }, 3);
    }

    private function assertParentChain(?int $parentOrderId, BomVersion $version, bool $requireCompleted = false): void
    {
        if ($parentOrderId === null) {
            return;
        }
        $parent = ProductionOrder::query()->findOrFail($parentOrderId);
        if ($requireCompleted && $parent->status !== 'completed') {
            throw new \DomainException('Parent WIP Production Order must be completed first.');
        }
        $parentVersion = BomVersion::query()->findOrFail($parent->bom_version_id);
        $parentBom = Bom::query()->findOrFail($parentVersion->bom_id);
        $parentOutputItemId = (int) $parentBom->output_item_id;
        if (! $version->components()->where('item_id', $parentOutputItemId)->exists()) {
            throw new \DomainException('Parent Production Order output is not a component of the child BOM.');
        }
    }

    private function expectedComponentQty(BomComponent $component, BomVersion $version, float $plannedOutput): float
    {
        return (float) $component->qty * ($plannedOutput / (float) $version->output_qty);
    }

    /** @param array<int, array{expected: float, actual: float}> $actualQuantities */
    private function recordVariances(
        ProductionOrder $order,
        \ESolution\Inventory\Models\Document $consumption,
        array $actualQuantities,
        float $actualOutputQty,
        float $unitCost,
        int $outputItemId,
    ): void {
        foreach ($actualQuantities as $itemId => $quantities) {
            $actualItemCost = (float) StockLedger::query()
                ->whereIn('document_line_id', $consumption->lines->pluck('id'))
                ->where('item_id', $itemId)
                ->sum('amount');
            $actualItemUnitCost = $quantities['actual'] > 0 ? $actualItemCost / $quantities['actual'] : 0.0;
            ProductionVariance::query()->create([
                'production_order_id' => $order->getKey(),
                'type' => 'scrap',
                'item_id' => $itemId,
                'expected_qty' => $quantities['expected'],
                'actual_qty' => $quantities['actual'],
                'difference_qty' => $quantities['actual'] - $quantities['expected'],
                'amount' => ($quantities['actual'] - $quantities['expected']) * $actualItemUnitCost,
            ]);
        }
        ProductionVariance::query()->create([
            'production_order_id' => $order->getKey(),
            'type' => 'yield',
            'item_id' => $outputItemId,
            'expected_qty' => $order->planned_qty,
            'actual_qty' => $actualOutputQty,
            'difference_qty' => $actualOutputQty - (float) $order->planned_qty,
            'amount' => ($actualOutputQty - (float) $order->planned_qty) * $unitCost,
        ]);
    }
}
