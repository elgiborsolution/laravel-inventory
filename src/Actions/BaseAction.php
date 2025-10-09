<?php
namespace ESolution\Inventory\Actions;

use ESolution\Inventory\Services\{InventoryManager, MovementPipeline, JournalManager, CostingManager};

abstract class BaseAction {
    public function __construct(protected InventoryManager $inv){}
    protected function pipeline(){ return $this->inv->pipeline(); }
    protected function journal(){ return $this->inv->journal(); }
    protected function costing($line){ return app(CostingManager::class)->driverFor($line); }
}
