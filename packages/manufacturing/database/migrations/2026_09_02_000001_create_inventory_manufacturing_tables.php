<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invm_boms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('name');
            $table->foreignId('output_item_id')->constrained('inv_items');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invm_bom_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_id')->constrained('invm_boms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->decimal('output_qty', 24, 6)->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['bom_id', 'version'], 'invm_bom_version_unique');
            $table->index(['bom_id', 'status', 'effective_from'], 'invm_bom_active_idx');
        });

        Schema::create('invm_bom_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_version_id')->constrained('invm_bom_versions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('uom_id')->constrained('inv_uoms');
            $table->decimal('qty', 24, 6);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
            $table->unique(['bom_version_id', 'item_id'], 'invm_bom_component_item_unique');
        });

        Schema::create('invm_production_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no', 96)->unique();
            $table->foreignId('bom_version_id')->constrained('invm_bom_versions');
            $table->foreignId('organization_id')->constrained('inv_organizations');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->string('status', 24)->default('planned');
            $table->string('source_mode', 8)->default('mts');
            $table->string('source_type')->nullable();
            $table->string('source_id', 128)->nullable();
            $table->foreignId('parent_order_id')->nullable()->constrained('invm_production_orders');
            $table->decimal('planned_qty', 24, 6);
            $table->decimal('actual_output_qty', 24, 6)->nullable();
            $table->decimal('actual_component_cost', 24, 6)->nullable();
            $table->decimal('output_unit_cost', 24, 6)->nullable();
            $table->foreignId('consumption_document_id')->nullable()->constrained('inv_documents');
            $table->foreignId('receipt_document_id')->nullable()->constrained('inv_documents');
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at'], 'invm_order_work_queue_idx');
            $table->index(['source_type', 'source_id'], 'invm_order_source_idx');
        });

        Schema::create('invm_production_variances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained('invm_production_orders')->cascadeOnDelete();
            $table->string('type', 24);
            $table->foreignId('item_id')->nullable()->constrained('inv_items');
            $table->decimal('expected_qty', 24, 6);
            $table->decimal('actual_qty', 24, 6);
            $table->decimal('difference_qty', 24, 6);
            $table->decimal('amount', 24, 6)->nullable();
            $table->timestamps();
            $table->unique(['production_order_id', 'type', 'item_id'], 'invm_variance_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invm_production_variances');
        Schema::dropIfExists('invm_production_orders');
        Schema::dropIfExists('invm_bom_components');
        Schema::dropIfExists('invm_bom_versions');
        Schema::dropIfExists('invm_boms');
    }
};
