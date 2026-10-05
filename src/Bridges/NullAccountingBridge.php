<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\Models\Document;

final class NullAccountingBridge implements AccountingBridge
{
    public function post(Document $document, AccountingPostingData $data): ?string
    {
        return null;
    }

    public function reverse(Document $originalDocument, string $reason): void {}
}
