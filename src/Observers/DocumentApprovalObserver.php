<?php

namespace ESolution\Inventory\Observers;

use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Exceptions\ApprovalConfigurationException;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Services\ResumeApprovedDocument;
use ESolution\Inventory\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

final class DocumentApprovalObserver
{
    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly ResumeApprovedDocument $resume,
    ) {}

    public function saved(Document $document): void
    {
        if (! $document->wasChanged('approval_status')) {
            return;
        }

        match ($document->approval_status) {
            'approved' => $this->approved((int) $document->getKey()),
            'rejected' => $this->rejected((int) $document->getKey()),
            'cancelled', 'pending_approval' => null,
            default => null,
        };
    }

    private function approved(int $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $document = Document::query()->lockForUpdate()->findOrFail($documentId);
            if ($document->posting_completed_at !== null || $document->status === DocumentStatus::POSTED) {
                return;
            }

            if ($document->status === DocumentStatus::SUBMITTED) {
                $this->workflow->transition($document, DocumentStatus::WAITING_APPROVAL);
            }
            if ($document->status === DocumentStatus::WAITING_APPROVAL) {
                $this->workflow->transition($document, DocumentStatus::APPROVED);
            }
            if ($document->status === DocumentStatus::APPROVED) {
                $this->resume->handle($documentId);
            }
        }, 3);
    }

    private function rejected(int $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $document = Document::query()->lockForUpdate()->findOrFail($documentId);
            if ($document->posting_completed_at !== null || $document->status === DocumentStatus::POSTED) {
                return;
            }

            if ($document->status === DocumentStatus::SUBMITTED) {
                $this->workflow->transition($document, DocumentStatus::WAITING_APPROVAL);
            }

            $configured = (string) config(
                "inventory.approval.rejection_status_map.{$document->document_type}",
                DocumentStatus::DRAFT->value,
            );
            $target = DocumentStatus::tryFrom($configured);
            if ($target === null || ! in_array($target, [DocumentStatus::DRAFT, DocumentStatus::CANCELLED], true)) {
                throw new ApprovalConfigurationException(
                    "Invalid rejection target '{$configured}' for document type '{$document->document_type}'.",
                );
            }

            if ($document->status === DocumentStatus::WAITING_APPROVAL) {
                $this->workflow->transition($document, $target);
            }
        }, 3);
    }
}
