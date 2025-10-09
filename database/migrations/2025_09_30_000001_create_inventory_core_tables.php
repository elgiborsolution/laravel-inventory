<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('inv_branches', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->json('account_overrides')->nullable();
            $t->timestamps();
        });

        Schema::create('inv_item_types', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->enum('valuation', ['fifo','average'])->default('fifo');
            $t->json('stages')->nullable();
            $t->timestamps();
        });

        Schema::create('inv_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->foreignId('item_type_id')->constrained('inv_item_types');
            $t->enum('valuation', ['fifo','average'])->nullable();
            $t->timestamps();
        });

        Schema::create('inv_warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('inv_branches');
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('inv_racks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('inv_warehouses');
            $t->string('code');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->unique(['warehouse_id','code']);
        });

        Schema::create('inv_stages', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('inv_item_type_stages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_type_id')->constrained('inv_item_types');
            $t->foreignId('stage_id')->constrained('inv_stages');
            $t->unsignedInteger('order');
            $t->timestamps();
            $t->unique(['item_type_id','stage_id']);
        });

        Schema::create('inv_documents', function (Blueprint $t) {
            $t->id();
            $t->string('external_id')->nullable()->unique();
            $t->enum('type', ['purchase','sale','purchase_return','sales_return','stock_opname','consignment','transfer_rack','transfer_warehouse','transfer_branch']);
            $t->date('date');
            $t->string('ref')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('inv_document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('inv_documents');
            $t->foreignId('item_id')->constrained('inv_items');
            $t->foreignId('branch_id')->constrained('inv_branches');
            $t->foreignId('warehouse_id')->constrained('inv_warehouses');
            $t->foreignId('rack_id')->nullable()->constrained('inv_racks');
            $t->decimal('qty', 18, 6);
            $t->decimal('unit_cost', 18, 6)->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('inv_stock_ledgers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('inv_documents');
            $t->foreignId('document_line_id')->constrained('inv_document_lines');
            $t->foreignId('item_id')->constrained('inv_items');
            $t->foreignId('branch_id')->constrained('inv_branches');
            $t->foreignId('warehouse_id')->constrained('inv_warehouses');
            $t->foreignId('rack_id')->nullable()->constrained('inv_racks');
            $t->string('stage_from')->nullable();
            $t->string('stage_to')->nullable();
            $t->enum('direction', ['in','out']);
            $t->decimal('qty', 18, 6);
            $t->decimal('unit_cost', 18, 6);
            $t->decimal('amount', 18, 2);
            $t->timestamps();
            $t->index(['item_id','branch_id','warehouse_id','rack_id','created_at'],'inv_ledger_loc_idx');
        });

        Schema::create('inv_cost_layers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('inv_items');
            $t->foreignId('branch_id')->constrained('inv_branches');
            $t->foreignId('warehouse_id')->constrained('inv_warehouses');
            $t->foreignId('rack_id')->nullable()->constrained('inv_racks');
            $t->decimal('qty_remain', 18, 6);
            $t->decimal('unit_cost', 18, 6);
            $t->foreignId('source_document_id')->nullable()->constrained('inv_documents');
            $t->timestamps();
            $t->index(['item_id','branch_id','warehouse_id','rack_id'],'inv_layers_scope_idx');
        });
    }

    public function down(): void {
        Schema::dropIfExists('inv_cost_layers');
        Schema::dropIfExists('inv_stock_ledgers');
        Schema::dropIfExists('inv_document_lines');
        Schema::dropIfExists('inv_documents');
        Schema::dropIfExists('inv_item_type_stages');
        Schema::dropIfExists('inv_stages');
        Schema::dropIfExists('inv_racks');
        Schema::dropIfExists('inv_warehouses');
        Schema::dropIfExists('inv_items');
        Schema::dropIfExists('inv_item_types');
        Schema::dropIfExists('inv_branches');
    }
};
