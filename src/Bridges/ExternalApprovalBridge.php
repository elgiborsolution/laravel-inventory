<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\ApprovalWorkflowGateway;
use ESolution\Inventory\DTO\ApprovalContext;
use ESolution\Inventory\Exceptions\ApprovalConfigurationException;
use ESolution\Inventory\Models\Document;

final class ExternalApprovalBridge implements ApprovalBridge
{
    public function __construct(private readonly ApprovalWorkflowGateway $gateway) {}

    public function checkAndSubmitIfRequired(Document $document, ApprovalContext $context): bool
    {
        $result = $this->gateway->checkApprovalRequired(
            module: $document->document_type,
            action: $context->action,
            data: $context->data,
            detailData: $context->detailData,
            tenantId: $context->tenantId,
        );

        if (! (bool) ($result['required'] ?? false)) {
            return false;
        }

        if ($document->approval_status !== null) {
            return true;
        }

        if (! isset($result['workflow_id'], $result['rule_id'])) {
            throw new ApprovalConfigurationException(
                'Approval is required but workflow_id or rule_id is missing from the approval response.',
            );
        }

        $this->gateway->submit(
            module: $document->document_type,
            approvableType: $document->getMorphClass(),
            approvableId: (string) $document->getKey(),
            workflowId: $result['workflow_id'],
            ruleId: $result['rule_id'],
            metadata: $context->metadata,
            tenantId: $context->tenantId,
        );

        return true;
    }
}
