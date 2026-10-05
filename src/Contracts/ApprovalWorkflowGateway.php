<?php

namespace ESolution\Inventory\Contracts;

interface ApprovalWorkflowGateway
{
    /** @return array<string, mixed> */
    public function checkApprovalRequired(
        string $module,
        string $action,
        array $data,
        array $detailData,
        ?string $tenantId,
    ): array;

    public function submit(
        string $module,
        string $approvableType,
        string $approvableId,
        int|string $workflowId,
        int|string $ruleId,
        array $metadata,
        ?string $tenantId,
    ): void;
}
