<?php

namespace ESolution\Inventory\Contracts;

interface AccountingJournalGateway
{
    public function post(array $payload, mixed $tenantIdentity = null): string;

    public function reverse(string $journalId, string $reason, mixed $tenantIdentity = null): void;

    public function findOriginalJournalId(string $sourceType, int|string $sourceId): ?string;
}
