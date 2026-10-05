<?php

use ESolution\Inventory\Bridges\ExternalApprovalBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\ApprovalWorkflowGateway;
use ESolution\Inventory\DTO\ApprovalContext;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Support\ApprovalPackageInspector;
use ESolution\Inventory\Tests\Fakes\FakeApprovalWorkflowGateway;
use ESolution\Inventory\Tests\Fakes\FakeIdentityResolver;
use Illuminate\Support\Facades\DB;

function bindApprovalStub(?FakeApprovalWorkflowGateway $gateway = null): FakeApprovalWorkflowGateway
{
    $gateway ??= new FakeApprovalWorkflowGateway();
    app()->instance(ApprovalWorkflowGateway::class, $gateway);
    app()->instance(ApprovalBridge::class, new ExternalApprovalBridge($gateway));

    return $gateway;
}

beforeEach(function (): void {
    $this->installInventorySchema();
});

test('AC3-01 absent optional package uses Null Bridge and posts without pause', function (): void {
    if (class_exists('ESolution\\ApprovalFlow\\Services\\WorkflowEngine')) {
        $this->markTestSkipped('The optional approval package is installed in this environment.');
    }

    $document = $this->postReceipt(externalId: 'AC3-01-GR');

    expect(app(ApprovalBridge::class))->toBeInstanceOf(NullApprovalBridge::class)
        ->and($document->status)->toBe(DocumentStatus::POSTED)
        ->and($document->approval_status)->toBeNull();
});

test('AC3-02 no matching rule posts immediately without submission', function (): void {
    $gateway = bindApprovalStub();
    $document = $this->postReceipt(externalId: 'AC3-02-GR');

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and($gateway->checks)->toHaveCount(1)
        ->and($gateway->submissions)->toBe([]);
});

test('AC3-03 matching rule submits once and pauses before stock effects', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval(workflowId: 10, ruleId: 20);
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC3-03-GR',
        lines: [new LineData(1, 1, 1, 2, unitCost: 5)],
        approvalData: ['total_amount' => 10],
        approvalMetadata: ['request_source' => 'manual'],
    ));

    expect($document->status)->toBe(DocumentStatus::WAITING_APPROVAL)
        ->and($document->approval_status)->toBe('pending_approval')
        ->and($gateway->submissions)->toHaveCount(1)
        ->and($gateway->submissions[0])->toMatchArray([
            'module' => 'purchase_receipt',
            'approvableType' => $document->getMorphClass(),
            'approvableId' => (string) $document->getKey(),
            'workflowId' => 10,
            'ruleId' => 20,
            'metadata' => ['request_source' => 'manual'],
        ])
        ->and($gateway->checks[0]['data']['total_amount'])->toBe(10)
        ->and($gateway->checks[0]['detailData'])->toHaveCount(1)
        ->and(StockLedger::query()->count())->toBe(0);
});

test('AC3-04 idempotent retry does not create another ApprovalInstance', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    $data = new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC3-04-GR',
        lines: [new LineData(1, 1, 1, 2, unitCost: 5)],
    );
    $first = app(InventoryManager::class)->post($data);
    $retry = app(InventoryManager::class)->post($data);

    expect($retry->getKey())->toBe($first->getKey())
        ->and($gateway->submissions)->toHaveCount(1)
        ->and(Document::query()->count())->toBe(1);

    $bridge = app(ApprovalBridge::class);
    $bridge->checkAndSubmitIfRequired($first->refresh(), new ApprovalContext('create', [], []));
    expect($gateway->submissions)->toHaveCount(1);
});

test('AC3-05 approved callback resumes and completes posting exactly once', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    $document = $this->postReceipt(3, 9, externalId: 'AC3-05-GR');

    $document->approval_status = 'approved';
    $document->save();
    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and($document->posting_completed_at)->not->toBeNull()
        ->and(StockLedger::query()->count())->toBe(1);

    $document->approval_status = 'approved';
    $document->save();
    expect(StockLedger::query()->count())->toBe(1);
});

test('AC3-06 rejected callback applies default and configured Core targets', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    $draftDocument = $this->postReceipt(externalId: 'AC3-06-DRAFT');
    $draftDocument->approval_status = 'rejected';
    $draftDocument->save();

    expect($draftDocument->refresh()->status)->toBe(DocumentStatus::DRAFT)
        ->and($draftDocument->approval_status)->toBe('rejected');

    config()->set('inventory.approval.rejection_status_map.purchase_receipt', 'cancelled');
    $cancelledDocument = $this->postReceipt(externalId: 'AC3-06-CANCEL');
    $cancelledDocument->approval_status = 'rejected';
    $cancelledDocument->save();

    expect($cancelledDocument->refresh()->status)->toBe(DocumentStatus::CANCELLED)
        ->and($cancelledDocument->approval_status)->toBe('rejected');
});

test('AC3-07 external cancellation does not overwrite Core status', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    $document = $this->postReceipt(externalId: 'AC3-07-GR');
    $document->approval_status = 'cancelled';
    $document->save();

    expect($document->refresh()->status)->toBe(DocumentStatus::WAITING_APPROVAL)
        ->and($document->approval_status)->toBe('cancelled')
        ->and(StockLedger::query()->count())->toBe(0);
});

test('AC3-08 Core and external approval status columns retain separate ownership', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    $document = $this->postReceipt(externalId: 'AC3-08-GR');

    expect($document->status)->toBe(DocumentStatus::WAITING_APPROVAL)
        ->and($document->approval_status)->toBe('pending_approval');

    $document->forceFill(['status' => DocumentStatus::DRAFT])->save();
    expect($document->refresh()->approval_status)->toBe('pending_approval');
});

test('AC3-09 unpublished workflow failure is diagnosable and rolls back', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    $gateway->rejectAsUnpublished();

    expect(fn() => $this->postReceipt(externalId: 'AC3-09-GR'))
        ->toThrow(RuntimeException::class, 'not published/active');
    expect(Document::query()->count())->toBe(0)
        ->and(StockLedger::query()->count())->toBe(0);
});

test('AC3-10 authorization and identity errors remain explicit', function (): void {
    $gateway = bindApprovalStub();
    $gateway->checkException = new RuntimeException('Approval service authorization requires an identity.');

    expect(fn() => $this->postReceipt(externalId: 'AC3-10-GR'))
        ->toThrow(RuntimeException::class, 'requires an identity');
    expect(Document::query()->count())->toBe(0);
});

test('AC3-11 tenant identity is passed to requirement check and submission', function (): void {
    $gateway = bindApprovalStub();
    $gateway->requireApproval();
    app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC3-11-GR',
        lines: [new LineData(1, 1, 1, 1, unitCost: 5)],
        tenantIdentity: 'tenant-approval-99',
    ));

    expect($gateway->checks[0]['tenantId'])->toBe('tenant-approval-99')
        ->and($gateway->submissions[0]['tenantId'])->toBe('tenant-approval-99');
});

test('approval validation command reports Null Bridge when package is absent', function (): void {
    app()->instance(ApprovalPackageInspector::class, new ApprovalPackageInspector(false));

    $this->artisan('inventory:approval:validate')
        ->expectsOutput('Approval Flow package is not installed; Null Approval Bridge is active.')
        ->assertSuccessful();
});

test('approval validation fails for incorrect status field or missing identity', function (): void {
    app()->instance(ApprovalPackageInspector::class, new ApprovalPackageInspector(true));
    config()->set('approval-flow.default_status_field', 'status_txt');
    $this->artisan('inventory:approval:validate')->assertFailed();

    config()->set('approval-flow.default_status_field', 'approval_status');
    config()->set('approval-flow.identity_resolver', null);
    $this->artisan('inventory:approval:validate')->assertFailed();
});

test('approval validation warns about unpublished workflows and service auth posture', function (): void {
    app()->instance(ApprovalPackageInspector::class, new ApprovalPackageInspector(true));
    config()->set('approval-flow.default_status_field', 'approval_status');
    config()->set('approval-flow.identity_resolver', FakeIdentityResolver::class);
    config()->set('approval-flow.enforce_service_auth', true);
    DB::statement('CREATE TABLE approval_workflows (id INTEGER PRIMARY KEY, status VARCHAR(32))');
    DB::statement('CREATE TABLE approval_rules (id INTEGER PRIMARY KEY, module VARCHAR(64), workflow_id INTEGER)');
    DB::table('approval_workflows')->insert(['id' => 10, 'status' => 'draft']);
    DB::table('approval_rules')->insert(['id' => 20, 'module' => 'purchase_receipt', 'workflow_id' => 10]);

    $this->artisan('inventory:approval:validate')
        ->expectsOutput("Approval workflow '10' for module 'purchase_receipt' is not published/active.")
        ->expectsOutput('Service authorization is enabled; confirm a system identity exists for queue/console execution.')
        ->assertSuccessful();
});
