<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Support\DocumentTypeDefinition;

class InMemoryDocumentTypeRegistry implements DocumentTypeRegistry
{
    private array $definitions = [];
    public function register(string $type, DocumentTypeDefinition $definition): void
    {
        $this->definitions[$type] = $definition;
    } public function get(string $type): DocumentTypeDefinition
    {
        return $this->definitions[$type] ?? throw new \DomainException("Unregistered document type: {$type}");
    } public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function all(): array
    {
        return $this->definitions;
    }
}
