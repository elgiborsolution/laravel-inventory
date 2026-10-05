<?php

namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\Support\DocumentTypeDefinition;

interface DocumentTypeRegistry
{
    public function register(string $type, DocumentTypeDefinition $definition): void;
    public function get(string $type): DocumentTypeDefinition;
    public function has(string $type): bool;

    /** @return array<string, DocumentTypeDefinition> */
    public function all(): array;
}
