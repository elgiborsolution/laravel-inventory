<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inv_cost_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->decimal('received_qty', 24, 6);
            $table->decimal('remaining_qty', 24, 6);
            $table->decimal('unit_cost', 24, 6);
            $table->timestamp('received_at');
            $table->foreignId('source_document_id')->nullable()->constrained('inv_documents');
            $table->foreignId('batch_id')->nullable()->constrained('inv_batches');
            $table->boolean('is_negative')->default(false);
            $table->timestamps();
            $table->index(['item_id', 'scope_type', 'scope_id', 'received_at'], 'inv_cost_layer_consume_idx');
            $table->index(['item_id', 'batch_id', 'remaining_qty'], 'inv_cost_layer_batch_qty_idx');
        });

        Schema::create('inv_stock_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_line_id')->constrained('inv_document_lines');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('storage_location_id')->nullable()->constrained('inv_storage_locations');
            $table->string('direction', 3);
            $table->decimal('qty', 24, 6);
            $table->decimal('qty_bonus', 24, 6)->default(0);
            $table->decimal('unit_cost', 24, 6);
            $table->decimal('amount', 24, 6);
            $table->foreignId('cost_layer_id')->nullable()->constrained('inv_cost_layers');
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'storage_location_id', 'created_at'], 'inv_ledger_scope_time_idx');
        });

        Schema::create('inv_stock_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->date('as_of');
            $table->decimal('running_qty', 24, 6)->default(0);
            $table->decimal('running_value', 24, 6)->default(0);
            $table->decimal('avg_cost', 24, 6)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'scope_type', 'scope_id', 'as_of'], 'inv_stock_card_scope_date_unique');
        });

        Schema::create('inv_cost_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('negative_layer_id')->constrained('inv_cost_layers');
            $table->foreignId('receipt_layer_id')->constrained('inv_cost_layers');
            $table->decimal('settled_qty', 24, 6);
            $table->decimal('provisional_unit_cost', 24, 6);
            $table->decimal('actual_unit_cost', 24, 6);
            $table->decimal('amount_delta', 24, 6);
            $table->timestamps();
            $table->unique(['negative_layer_id', 'receipt_layer_id'], 'inv_cost_adjustment_settlement_unique');
            $table->index(['item_id', 'scope_type', 'scope_id', 'created_at'], 'inv_cost_adjustment_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_cost_adjustments');
        Schema::dropIfExists('inv_stock_cards');
        Schema::dropIfExists('inv_stock_ledgers');
        Schema::dropIfExists('inv_cost_layers');
    }
};
