<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inv_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 64);
            $table->string('status', 32)->default('draft');
            $table->string('approval_status', 32)->nullable();
            $table->foreignId('organization_id')->constrained('inv_organizations');
            $table->string('external_id', 128)->nullable();
            $table->string('idempotency_hash', 64)->nullable();
            $table->string('party_type')->nullable();
            $table->string('party_id', 128)->nullable();
            $table->string('source_type')->default('inventory');
            $table->string('source_id', 128)->nullable();
            $table->date('trx_date');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('posting_started_at')->nullable();
            $table->timestamp('posting_completed_at')->nullable();
            $table->string('posting_marker', 128)->nullable()->unique();
            $table->foreignId('reversal_of_id')->nullable()->constrained('inv_documents');
            $table->text('reversal_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'source_type', 'external_id'], 'inv_document_idempotency_unique');
            $table->index(['organization_id', 'document_type', 'status', 'trx_date'], 'inv_document_query_idx');
        });

        Schema::create('inv_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('inv_documents');
            $table->unsignedInteger('line_no');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('uom_id')->constrained('inv_uoms');
            $table->decimal('qty', 24, 6);
            $table->decimal('qty_bonus', 24, 6)->default(0);
            $table->decimal('unit_cost', 24, 6)->nullable();
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('storage_location_id')->nullable()->constrained('inv_storage_locations');
            $table->foreignId('batch_id')->nullable()->constrained('inv_batches');
            $table->foreignId('serial_id')->nullable()->constrained('inv_serials');
            $table->string('party_type')->nullable();
            $table->string('party_id', 128)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'line_no'], 'inv_document_line_number_unique');
            $table->index(['item_id', 'warehouse_id', 'storage_location_id'], 'inv_document_line_stock_scope_idx');
        });

        Schema::create('inv_audit_trails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('inv_documents');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type')->nullable();
            $table->string('actor_id', 128)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['document_id', 'created_at'], 'inv_audit_document_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_audit_trails');
        Schema::dropIfExists('inv_document_lines');
        Schema::dropIfExists('inv_documents');
    }
};
