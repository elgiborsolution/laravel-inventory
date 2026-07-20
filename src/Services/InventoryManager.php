<?php
namespace ESolution\Inventory\Services;

use Illuminate\Support\Facades\DB;
use ESolution\Inventory\DTO\{DocumentData, LineData};
use ESolution\Inventory\Models\{Document, DocumentLine};
use ESolution\Inventory\Enums\DocumentType;
use ESolution\Inventory\Services\{CostingManager, MovementPipeline, JournalManager};

class InventoryManager
{
    public function __construct(
        protected $app
    ){}

    public function post(DocumentData $docData)
    {
        $document = DB::transaction(function() use ($docData){
            $doc = Document::create([
                'external_id'=>$docData->external_id,
                'type'=>$docData->type,
                'date'=>$docData->date,
                'ref'=>$docData->ref,
                'meta'=>$docData->meta,
            ]);

            $lines = [];
            foreach ($docData->lines as $ld) {
                /** @var LineData $ld */
                $lines[] = DocumentLine::create([
                    'document_id'=>$doc->id,
                    'item_id'=>$ld->itemId,
                    'branch_id'=>$ld->branchId,
                    'warehouse_id'=>$ld->warehouseId,
                    'rack_id'=>$ld->rackId,
                    'qty'=>$ld->qty,
                    'unit_cost'=>$ld->unitCost,
                    'meta'=>$ld->meta,
                ]);
            }
            $doc->setRelation('lines', collect($lines));

            return match($docData->type){
                DocumentType::PURCHASE->value        => (new \ESolution\Inventory\Actions\PostPurchase($this))->handle($doc),
                DocumentType::SALE->value            => (new \ESolution\Inventory\Actions\PostSale($this))->handle($doc),
                DocumentType::PURCHASE_RETURN->value => (new \ESolution\Inventory\Actions\PostPurchaseReturn($this))->handle($doc),
                DocumentType::SALES_RETURN->value    => (new \ESolution\Inventory\Actions\PostSalesReturn($this))->handle($doc),
                DocumentType::STOCK_OPNAME->value    => (new \ESolution\Inventory\Actions\PostStockOpname($this))->handle($doc),
                DocumentType::CONSIGNMENT->value     => (new \ESolution\Inventory\Actions\PostConsignment($this))->handle($doc),
                DocumentType::TRANSFER_RACK->value   => (new \ESolution\Inventory\Actions\PostTransferRack($this))->handle($doc),
                DocumentType::TRANSFER_WAREHOUSE->value => (new \ESolution\Inventory\Actions\PostTransferWarehouse($this))->handle($doc),
                DocumentType::TRANSFER_BRANCH->value => (new \ESolution\Inventory\Actions\PostTransferBranch($this))->handle($doc),
                default => $doc,
            };
        });

        app(\ESolution\Inventory\Services\StockCardManager::class)->generateForDocument($document);

        return $document;
    }

    // quick helpers
    public function costingDriver($line){ return app(CostingManager::class)->driverFor($line); }
    public function pipeline(){ return app(MovementPipeline::class); }
    public function journal(){ return app(JournalManager::class); }

    // Transfer helpers (simple wrappers around post())
    public function transferRack(array $params){ /* left as exercise */ }
    public function transferWarehouse(array $params){ /* left as exercise */ }
    public function transferBranch(array $params){ /* left as exercise */ }
}
