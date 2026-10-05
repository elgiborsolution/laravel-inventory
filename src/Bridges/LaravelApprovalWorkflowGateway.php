<?php

namespace ESolution\Inventory\Bridges;

use ESolution\Inventory\Contracts\ApprovalWorkflowGateway;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

final class LaravelApprovalWorkflowGateway implements ApprovalWorkflowGateway
{
    private const WORKFLOW_ENGINE = 'ESolution\\ApprovalFlow\\Services\\WorkflowEngine';

    public function __construct(private readonly Container $container) {}

    public function checkApprovalRequired(
        string $module,
        string $action,
        array $data,
        array $detailData,
        ?string $tenantId,
    ): array {
        $result = $this->engine()->checkApprovalRequired(
            module: $module,
            action: $action,
            data: $data,
            detailData: $detailData,
            tenantId: $tenantId,
        );
        if (! is_array($result)) {
            throw new RuntimeException('Approval WorkflowEngine returned an invalid requirement response.');
        }

        return $result;
    }

    public function submit(
        string $module,
        string $approvableType,
        string $approvableId,
        int|string $workflowId,
        int|string $ruleId,
        array $metadata,
        ?string $tenantId,
    ): void {
        $this->engine()->submit(
            module: $module,
            approvableType: $approvableType,
            approvableId: $approvableId,
            workflowId: $workflowId,
            ruleId: $ruleId,
            metadata: $metadata,
            tenantId: $tenantId,
        );
    }

    private function engine(): object
    {
        if (! class_exists(self::WORKFLOW_ENGINE)) {
            throw new RuntimeException('e-solution/laravel-approval-flow is not installed.');
        }

        return $this->container->make(self::WORKFLOW_ENGINE);
    }
}
