<?php

namespace ESolution\Inventory\Tests\Fakes;

use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\DTO\ApprovalContext;
use ESolution\Inventory\Models\Document;

final class SpyApprovalBridge implements ApprovalBridge
{
    public int $checks = 0;

    public function __construct(public bool $required = false) {}

    public function checkAndSubmitIfRequired(Document $document, ApprovalContext $context): bool
    {
        ++$this->checks;
        if ($this->required) {
            $document->forceFill(['approval_status' => 'pending_approval'])->save();
        }

        return $this->required;
    }
}
