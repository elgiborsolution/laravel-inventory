<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\AuditTrail;
use ESolution\Inventory\Models\Document;

class WorkflowEngine
{
    private array $hooks = [];
    private const TRANSITIONS = ['draft' => ['submitted','cancelled'],'submitted' => ['waiting_approval','posted','cancelled'],'waiting_approval' => ['approved','draft','cancelled'],'approved' => ['posted','cancelled'],'posted' => ['reversed','completed']];
    public function transition(Document $document, DocumentStatus $to, array $actor = []): void
    {
        $from = $document->status instanceof DocumentStatus ? $document->status->value : (string) $document->status;
        if (!in_array($to->value, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException("Invalid document transition {$from} -> {$to->value}.");
        }$document->forceFill(['status' => $to->value])->save();
        AuditTrail::create(['document_id' => $document->id,'from_status' => $from,'to_status' => $to->value,'actor_type' => $actor['type'] ?? null,'actor_id' => $actor['id'] ?? null,'context' => $actor['context'] ?? null]);
        foreach ($this->hooks[$document->document_type] ?? [] as $hook) {
            $hook($document, $from, $to->value);
        }
    }public function onTransition(string $type, callable $hook): void
    {
        $this->hooks[$type][] = $hook;
    }
}
