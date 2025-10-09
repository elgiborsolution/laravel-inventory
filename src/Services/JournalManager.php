<?php
namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\JournalPoster;
use ESolution\Inventory\Models\{Journal, JournalEntry};
use Illuminate\Support\Arr;

class JournalManager implements JournalPoster
{
    public function post(string $date, string $memo, array $entries, int $documentId): int
    {
        $j = Journal::create(['document_id'=>$documentId,'date'=>$date,'memo'=>$memo]);
        foreach ($entries as $e) {
            JournalEntry::create([
                'journal_id'=>$j->id,
                'account'=>$e['account'],
                'dc'=>$e['dc'],
                'amount'=>$e['amount'],
                'meta'=>$e['meta'] ?? null
            ]);
        }
        return $j->id;
    }
}
