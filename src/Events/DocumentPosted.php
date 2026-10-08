<?php

namespace ESolution\Inventory\Events;

use ESolution\Inventory\Models\Document;

final class DocumentPosted
{
    public function __construct(public readonly Document $document) {}

    public static function dispatchAfterCommit(Document $document): void
    {
        $event = new self($document);
        $connection = $document->getConnection();
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(static fn() => event($event));

            return;
        }

        event($event);
    }
}
