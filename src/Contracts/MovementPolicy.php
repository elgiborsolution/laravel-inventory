<?php

namespace ESolution\Inventory\Contracts;

use ESolution\Inventory\Models\DocumentLine;

interface MovementPolicy
{
    public function name(): string;

    public function validate(DocumentLine $line, string $direction): void;
}
