<?php
namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\Models\DocumentLine;

interface MovementPolicy {
    public function nextStageFor(int $itemId, ?string $from): ?string;
    public function move(DocumentLine $line, float $qty, ?string $from, ?string $to, float $unitCost, string $direction): void;
}
