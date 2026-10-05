<?php

namespace ESolution\Inventory\Tests\Fakes;

use ESolution\Inventory\Contracts\ApprovalWorkflowGateway;
use RuntimeException;
use Throwable;

final class FakeApprovalWorkflowGateway implements ApprovalWorkflowGateway
{
    /** @var array<string, mixed> */
    public array $requirement = ['required' => false];

    /** @var list<array<string, mixed>> */
    public array $checks = [];

    /** @var list<array<string, mixed>> */
    public array $submissions = [];

    public ?Throwable $checkException = null;

    public ?Throwable $submitException = null;

    public bool $writePendingStatus = true;

    public function checkApprovalRequired(
        string $module,
        string $action,
        array $data,
        array $detailData,
        ?string $tenantId,
    ): array {
        if ($this->checkException !== null) {
            throw $this->checkException;
        }
        $this->checks[] = compact('module', 'action', 'data', 'detailData', 'tenantId');

        return $this->requirement;
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
        if ($this->submitException !== null) {
            throw $this->submitException;
        }
        $this->submissions[] = compact(
            'module',
            'approvableType',
            'approvableId',
            'workflowId',
            'ruleId',
            'metadata',
            'tenantId',
        );

        if ($this->writePendingStatus) {
            $model = $approvableType::query()->findOrFail($approvableId);
            $model->approval_status = 'pending_approval';
            $model->save();
        }
    }

    public function requireApproval(int|string $workflowId = 1, int|string $ruleId = 1): void
    {
        $this->requirement = [
            'required' => true,
            'workflow_id' => $workflowId,
            'rule_id' => $ruleId,
        ];
    }

    public function rejectAsUnpublished(): void
    {
        $this->submitException = new RuntimeException('Approval workflow is not published/active.');
    }
}
