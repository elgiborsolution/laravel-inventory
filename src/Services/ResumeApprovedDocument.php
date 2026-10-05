<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Models\Document;

final class ResumeApprovedDocument
{
    public function __construct(private readonly PostingEngine $posting) {}

    public function handle(int $documentId): Document
    {
        return $this->posting->resumeApproved($documentId);
    }

    public function __invoke(int $documentId): Document
    {
        return $this->handle($documentId);
    }
}
