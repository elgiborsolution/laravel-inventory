<?php

namespace ESolution\Inventory\Tests\Concerns;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Services\InventoryManager;
use Illuminate\Support\Facades\DB;

trait BuildsInventoryScenario
{
    protected function installInventorySchema(): void
    {
        if (getenv('INVENTORY_TEST_DATABASE')) {
            // TestCase validates the dedicated database name before this destructive test reset.
            $this->artisan('migrate:fresh', ['--database' => 'testing'])->assertSuccessful();
        }
        $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();

        DB::table('inv_organizations')->insert([
            'id' => 1,
            'type' => 'warehouse',
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inv_item_categories')->insert([
            'id' => 1,
            'code' => 'GENERAL',
            'name' => 'General',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inv_uoms')->insert([
            'id' => 1,
            'code' => 'PCS',
            'name' => 'Pieces',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inv_items')->insert([
            [
                'id' => 1,
                'sku' => 'STOCK-1',
                'name' => 'Stock Item',
                'item_type' => 'stock',
                'item_category_id' => 1,
                'base_uom_id' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'sku' => 'SERVICE-1',
                'name' => 'Service Item',
                'item_type' => 'service',
                'item_category_id' => 1,
                'base_uom_id' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function postReceipt(
        float $quantity = 10,
        float $unitCost = 5,
        float $bonus = 0,
        string $externalId = 'GR-TEST',
        ?int $locationId = null,
    ): Document {
        return app(InventoryManager::class)->post(new DocumentData(
            type: 'purchase_receipt',
            organizationId: 1,
            trxDate: '2026-08-31',
            externalId: $externalId,
            lines: [new LineData(1, 1, 1, $quantity, $locationId, $bonus, $unitCost)],
        ));
    }

    protected function postIssue(
        float $quantity,
        float $bonus = 0,
        string $externalId = 'GI-TEST',
        ?int $locationId = null,
    ): Document {
        return app(InventoryManager::class)->post(new DocumentData(
            type: 'goods_issue',
            organizationId: 1,
            trxDate: '2026-08-31',
            externalId: $externalId,
            lines: [new LineData(1, 1, 1, $quantity, $locationId, $bonus)],
        ));
    }
}
