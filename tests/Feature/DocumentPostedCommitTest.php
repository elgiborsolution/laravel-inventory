<?php

use ESolution\Inventory\Events\DocumentPosted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->installInventorySchema();
});

test('posting event waits for the outer transaction commit', function (): void {
    $received = [];
    Event::listen(DocumentPosted::class, function (DocumentPosted $event) use (&$received): void {
        $received[] = [$event->document->id, $event->document->getConnection()->transactionLevel()];
    });
    DB::beginTransaction();
    try {
        $document = $this->postReceipt(externalId: 'COMMIT-EVENT');
        expect($received)->toBe([]);
        DB::commit();
        expect($received)->toBe([[$document->id, 0]]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

test('rolled back posting never delivers its event', function (): void {
    $received = [];
    Event::listen(DocumentPosted::class, function (DocumentPosted $event) use (&$received): void {
        $received[] = $event->document->external_id;
    });
    DB::beginTransaction();
    try {
        $this->postReceipt(externalId: 'ROLLED-BACK-EVENT');
        expect($received)->toBe([]);
    } finally {
        DB::rollBack();
    }
    $this->postReceipt(externalId: 'COMMITTED-EVENT');
    expect($received)->toBe(['COMMITTED-EVENT']);
});
