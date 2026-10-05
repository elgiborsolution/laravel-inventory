<?php

namespace ESolution\Inventory\Tests\Fakes;

use ESolution\Inventory\Contracts\AccountingJournalGateway;
use RuntimeException;
use Throwable;

final class FakeAccountingJournalGateway implements AccountingJournalGateway
{
    /** @var list<array{payload: array<string, mixed>, tenant: mixed}> */
    public array $posts = [];

    /** @var list<array{journal_id: string, reason: string, tenant: mixed}> */
    public array $reversals = [];

    public ?Throwable $postException = null;

    public ?string $originalJournalId = null;

    public bool $rejectDuplicateReversal = true;

    public function post(array $payload, mixed $tenantIdentity = null): string
    {
        if ($this->postException !== null) {
            throw $this->postException;
        }

        $this->posts[] = ['payload' => $payload, 'tenant' => $tenantIdentity];

        return 'journal-' . count($this->posts);
    }

    public function reverse(string $journalId, string $reason, mixed $tenantIdentity = null): void
    {
        if ($this->rejectDuplicateReversal && $this->reversals !== []) {
            throw new RuntimeException('Journal has already been reversed.');
        }

        $this->reversals[] = ['journal_id' => $journalId, 'reason' => $reason, 'tenant' => $tenantIdentity];
    }

    public function findOriginalJournalId(string $sourceType, int|string $sourceId): ?string
    {
        return $this->originalJournalId;
    }
}
