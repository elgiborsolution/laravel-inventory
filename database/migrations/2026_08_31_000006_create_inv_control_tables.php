<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inv_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->string('source_type');
            $table->string('source_id', 128);
            $table->decimal('reserved_qty', 24, 6);
            $table->decimal('consumed_qty', 24, 6)->default(0);
            $table->decimal('released_qty', 24, 6)->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'status'], 'inv_reservation_available_idx');
            $table->index(['source_type', 'source_id'], 'inv_reservation_source_idx');
        });

        Schema::create('inv_reservation_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained('inv_reservations');
            $table->foreignId('document_line_id')->nullable()->constrained('inv_document_lines');
            $table->string('idempotency_key', 128);
            $table->decimal('qty', 24, 6);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['reservation_id', 'idempotency_key'], 'inv_reservation_consume_idempotency_unique');
        });

        Schema::create('inv_stock_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->nullable()->constrained('inv_items');
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->decimal('locked_qty', 24, 6)->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['item_id', 'scope_type', 'scope_id', 'expires_at'], 'inv_stock_lock_scope_idx');
        });

        Schema::create('inv_policy_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_type', 64);
            $table->foreignId('item_id')->nullable()->constrained('inv_items');
            $table->foreignId('location_id')->nullable()->constrained('inv_storage_locations');
            $table->json('value');
            $table->timestamps();
            $table->unique(['policy_type', 'item_id', 'location_id'], 'inv_policy_override_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_policy_overrides');
        Schema::dropIfExists('inv_stock_locks');
        Schema::dropIfExists('inv_reservation_consumptions');
        Schema::dropIfExists('inv_reservations');
    }
};
