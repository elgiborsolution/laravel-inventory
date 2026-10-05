<?php

namespace ESolution\Inventory\Bridges\Support;

use ESolution\Inventory\Exceptions\AccountingMappingIncompleteException;

final class ServiceCodeResolver
{
    public function resolve(string $documentType, ?string $callerSelection = null): ?string
    {
        $map = (array) config('inventory.accounting.service_code_map', []);
        if (! array_key_exists($documentType, $map)) {
            throw new AccountingMappingIncompleteException(
                "Accounting service_code mapping is missing for document type '{$documentType}'.",
            );
        }

        $configured = $map[$documentType];
        if ($callerSelection !== null) {
            $allowed = is_array($configured) ? $configured : [$configured];
            if (! in_array($callerSelection, $allowed, true)) {
                throw new AccountingMappingIncompleteException(
                    "Accounting service_code '{$callerSelection}' is not allowed for document type '{$documentType}'.",
                );
            }

            return $this->validate($callerSelection, $documentType);
        }

        if (is_array($configured)) {
            throw new AccountingMappingIncompleteException(
                "Document type '{$documentType}' requires the caller to select an accounting service_code.",
            );
        }

        return $configured === null ? null : $this->validate((string) $configured, $documentType);
    }

    private function validate(string $serviceCode, string $documentType): string
    {
        if ($serviceCode === '' || preg_match('/^[A-Z][A-Z0-9_]*$/', $serviceCode) !== 1) {
            throw new AccountingMappingIncompleteException(
                "Invalid accounting service_code '{$serviceCode}' for document type '{$documentType}'.",
            );
        }

        return $serviceCode;
    }
}
