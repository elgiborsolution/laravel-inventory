<?php

namespace ESolution\Inventory\Commands;

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ValidateAccountingCommand extends Command
{
    private const JOURNAL_SERVICE = 'ESolution\\LaravelAccounting\\Services\\JournalService';

    protected $signature = 'inventory:accounting:validate';

    protected $description = 'Validate Inventory Accounting Bridge prerequisites and service mappings';

    public function handle(DocumentTypeRegistry $documentTypes): int
    {
        if (! (bool) config('inventory.accounting.enabled', false)) {
            $this->info('Inventory accounting is disabled; Null Accounting Bridge is active.');

            return self::SUCCESS;
        }

        if (! class_exists(self::JOURNAL_SERVICE)) {
            $this->error('Accounting is enabled but elgibor-solution/laravel-accounting is not installed.');

            return self::FAILURE;
        }

        $map = (array) config('inventory.accounting.service_code_map', []);
        $codes = [];
        foreach ($map as $configured) {
            foreach (is_array($configured) ? $configured : [$configured] as $code) {
                if (is_string($code) && $code !== '') {
                    $codes[] = $code;
                }
            }
        }
        $codes = array_values(array_unique($codes));

        try {
            $existing = DB::connection(config('inventory.accounting.connection'))
                ->table('acc_services')
                ->whereIn('service_code', $codes)
                ->pluck('service_code')
                ->all();
        } catch (Throwable $exception) {
            $this->error('Unable to validate accounting catalog: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $missing = array_values(array_diff($codes, $existing));
        if ($missing !== []) {
            $this->error('Unknown accounting service_code: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        foreach (array_keys($documentTypes->all()) as $type) {
            if (! array_key_exists($type, $map)) {
                $this->warn("Registered document type '{$type}' has no accounting mapping.");
            }
        }

        if (($map['warehouse_transfer.cross_company'] ?? null) === 'STOCK_TRANSFER') {
            $hasTransferTemplate = DB::connection(config('inventory.accounting.connection'))
                ->table('acc_service_accounts')
                ->where('service_code', 'STOCK_TRANSFER')
                ->exists();
            if (! $hasTransferTemplate) {
                $this->warn('STOCK_TRANSFER has no account mapping template.');
            }
        }

        $this->info('Inventory accounting configuration is valid.');

        return self::SUCCESS;
    }
}
