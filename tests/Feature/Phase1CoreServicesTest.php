<?php

use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\AuditTrail;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Services\ConfigurationDepthResolver;
use ESolution\Inventory\Services\PolicyEngine;
use ESolution\Inventory\Services\WorkflowEngine;

beforeEach(function (): void {
    $this->installInventorySchema();
});

it('resolves registered policy before configuration fallback', function (): void {
    config()->set('inventory.policies.posting.enabled', false);
    $policies = app(PolicyEngine::class);

    expect($policies->evaluate('posting'))->toBeFalse();

    $policies->register('posting', fn(): bool => true);

    expect($policies->evaluate('posting'))->toBeTrue();
});

it('persists valid workflow transitions and rejects invalid transitions', function (): void {
    $document = Document::create([
        'document_type' => 'purchase_receipt',
        'organization_id' => 1,
        'source_type' => 'test',
        'trx_date' => '2026-08-31',
        'status' => DocumentStatus::DRAFT,
    ]);
    $workflow = app(WorkflowEngine::class);
    $workflow->transition($document, DocumentStatus::SUBMITTED, ['type' => 'user', 'id' => '10']);

    expect($document->refresh()->status)->toBe(DocumentStatus::SUBMITTED)
        ->and(AuditTrail::query()->where('document_id', $document->getKey())->count())->toBe(1);

    expect(fn() => $workflow->transition($document, DocumentStatus::REVERSED))
        ->toThrow(DomainException::class, 'Invalid document transition');
});

it('resolves warehouse and rack costing scopes deterministically', function (): void {
    $resolver = app(ConfigurationDepthResolver::class);

    config()->set('inventory.costing.scope', 'warehouse');
    expect($resolver->costingScope(1, 10))->toBe(['warehouse', 1]);

    config()->set('inventory.costing.scope', 'rack');
    expect($resolver->costingScope(1, 10))->toBe(['rack', 10])
        ->and(fn() => $resolver->costingScope(1))->toThrow(DomainException::class);
});

it('runs transition hooks only for their registered document type', function (): void {
    $calls = 0;
    $workflow = app(WorkflowEngine::class);
    $workflow->onTransition('purchase_receipt', function () use (&$calls): void {
        ++$calls;
    });
    $document = Document::create([
        'document_type' => 'purchase_receipt',
        'organization_id' => 1,
        'source_type' => 'test',
        'trx_date' => '2026-08-31',
        'status' => DocumentStatus::DRAFT,
    ]);

    $workflow->transition($document, DocumentStatus::SUBMITTED);

    expect($calls)->toBe(1);
});
