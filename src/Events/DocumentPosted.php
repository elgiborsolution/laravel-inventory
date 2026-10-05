<?php

namespace ESolution\Inventory\Events;

use ESolution\Inventory\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class DocumentPosted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Document $document) {}
}
