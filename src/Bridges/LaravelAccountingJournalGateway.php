<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Contracts\AccountingJournalGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LaravelAccountingJournalGateway implements AccountingJournalGateway
{
    private const JOURNAL_SERVICE = 'ESolution\\LaravelAccounting\\Services\\JournalService';

    public function __construct(private readonly Container $container) {}

    public function post(array $payload, mixed $tenantIdentity = null): string
    {
        $service = $this->journalService();
        $tenantKey = config('inventory.accounting.tenant_payload_key');
        if (is_string($tenantKey) && $tenantKey !== '' && $tenantIdentity !== null) {
            $payload[$tenantKey] = $tenantIdentity;
        }
        $journal = $service->journalByMapping($payload);
        $id = is_object($journal) ? ($journal->id ?? null) : null;
        if ($id === null) {
            throw new RuntimeException('Accounting JournalService did not return a journal id.');
        }

        return (string) $id;
    }

    public function reverse(string $journalId, string $reason, mixed $tenantIdentity = null): void
    {
        $this->journalService()->reverse($journalId, $reason);
    }

    public function findOriginalJournalId(string $sourceType, int|string $sourceId): ?string
    {
        $id = DB::connection(config('inventory.accounting.connection'))
            ->table('acc_journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('is_reversal', false)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function journalService(): object
    {
        if (! class_exists(self::JOURNAL_SERVICE)) {
            throw new RuntimeException('elgibor-solution/laravel-accounting is not installed.');
        }

        return $this->container->make(self::JOURNAL_SERVICE);
    }
}
