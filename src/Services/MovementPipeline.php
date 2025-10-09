<?php
namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\MovementPolicy;
use ESolution\Inventory\Models\{DocumentLine, StockLedger, Item, ItemType, Stage, ItemTypeStage};

class MovementPipeline implements MovementPolicy
{
    public function nextStageFor(int $itemId, ?string $from): ?string
    {
        $stages = $this->stagesForItemId($itemId);
        if ($from === null) return $stages[0] ?? null;
        $idx = array_search($from, $stages, true);
        return ($idx !== false && isset($stages[$idx+1])) ? $stages[$idx+1] : null;
    }

    public function stagesForItemId(int $itemId): array
    {
        $item = Item::findOrFail($itemId);
        $type = ItemType::findOrFail($item->item_type_id);
        $config = inv_cfg('item_type_stages');
        return $config[$type->code] ?? [];
    }

    public function move(DocumentLine $line, float $qty, ?string $from, ?string $to, float $unitCost, string $direction): void
    {
        StockLedger::create([
            'document_id'=>$line->document_id,
            'document_line_id'=>$line->id,
            'item_id'=>$line->item_id,
            'branch_id'=>$line->branch_id,
            'warehouse_id'=>$line->warehouse_id,
            'rack_id'=>$line->rack_id,
            'stage_from'=>$from,
            'stage_to'=>$to,
            'direction'=>$direction,
            'qty'=>$qty,
            'unit_cost'=>$unitCost,
            'amount'=>$qty * $unitCost,
        ]);
    }
}
