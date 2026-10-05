<?php

namespace ESolution\Inventory\Support;

final class DocumentTypeDefinition
{
    public function __construct(public string $direction, public array $allowedFrom = ['submitted','approved'], public bool $costing = true, public ?\Closure $beforePosting = null)
    {
        if (!in_array($direction, ['in','out','none'], true)) {
            throw new \InvalidArgumentException('Invalid inventory direction.');
        }
    }
}
