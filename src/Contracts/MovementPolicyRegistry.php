<?php

namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\Models\DocumentLine;

interface MovementPolicyRegistry
{
    /** @param class-string<MovementPolicy> $policyClass */
    public function register(string $inventoryModel, string $policyClass): void;

    public function resolve(DocumentLine $line): ?MovementPolicy;

    public function resolvedModel(DocumentLine $line): string;
}
