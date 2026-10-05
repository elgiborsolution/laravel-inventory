<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\DTO\ApprovalContext;
use ESolution\Inventory\Models\Document;

final class NullApprovalBridge implements ApprovalBridge
{
    public function checkAndSubmitIfRequired(Document $document, ApprovalContext $context): bool
    {
        return false;
    }
}
