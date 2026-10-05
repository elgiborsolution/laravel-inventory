<?php

namespace ESolution\Inventory\Bridges\Support;

use ESolution\Inventory\Exceptions\AccountingMappingIncompleteException;

final class MappingKeyGuard
{
    /** @param list<array<string, mixed>> $lines */
    public function assertSafe(array $lines, string $servicePrefix): void
    {
        $expectedPrefix = strtolower($servicePrefix) . '_';
        foreach ($lines as $index => $line) {
            $key = $line['mapping_key'] ?? null;
            if (! is_string($key) || ! str_starts_with(strtolower($key), $expectedPrefix)) {
                throw new AccountingMappingIncompleteException(
                    "Accounting line {$index} mapping_key must start with '{$expectedPrefix}'.",
                );
            }
            if (! array_key_exists('amount', $line) || ! is_numeric($line['amount'])) {
                throw new AccountingMappingIncompleteException(
                    "Accounting line {$index} must contain a numeric amount.",
                );
            }
            if (array_key_exists('account_id', $line) && ! is_int($line['account_id']) && ! is_string($line['account_id'])) {
                throw new AccountingMappingIncompleteException(
                    "Accounting line {$index} account_id must be an integer or string.",
                );
            }
        }
    }
}
