<?php

namespace ESolution\Inventory\Support;

final class ApprovalPackageInspector
{
    public function __construct(private readonly ?bool $forced = null) {}

    public function installed(): bool
    {
        return $this->forced
            ?? class_exists('ESolution\\ApprovalFlow\\Services\\WorkflowEngine');
    }
}
