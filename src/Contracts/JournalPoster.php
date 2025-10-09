<?php
namespace ESolution\Inventory\Contracts;

interface JournalPoster {
    public function post(string $date, string $memo, array $entries, int $documentId): int;
}
