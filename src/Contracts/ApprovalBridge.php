<?php

namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\DTO\ApprovalContext;
use ESolution\Inventory\Models\Document;

interface ApprovalBridge
{
    public function checkAndSubmitIfRequired(Document $document, ApprovalContext $context): bool;
}
