<?php

namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\Models\Document;

interface AccountingBridge
{
    public function post(Document $document, AccountingPostingData $data): ?string;

    public function reverse(Document $originalDocument, string $reason): void;
}
