<?php

namespace ESolution\Inventory\Tests\Fakes;

use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\Models\Document;

final class SpyAccountingBridge implements AccountingBridge
{
    public int $posts = 0;

    public int $reversals = 0;

    public ?\Throwable $postException = null;

    public function post(Document $document, AccountingPostingData $data): ?string
    {
        ++$this->posts;
        if ($this->postException !== null) {
            throw $this->postException;
        }

        return null;
    }

    public function reverse(Document $originalDocument, string $reason): void
    {
        ++$this->reversals;
    }
}
