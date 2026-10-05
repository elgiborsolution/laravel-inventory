<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invf_recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('name');
            $table->foreignId('output_item_id')->constrained('inv_items');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invf_recipe_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained('invf_recipes')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->decimal('output_qty', 24, 6)->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['recipe_id', 'version'], 'invf_recipe_version_unique');
            $table->index(['recipe_id', 'status', 'effective_from'], 'invf_recipe_active_idx');
        });

        Schema::create('invf_recipe_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained('invf_recipe_versions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('uom_id')->constrained('inv_uoms');
            $table->decimal('qty', 24, 6);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
            $table->unique(['recipe_version_id', 'item_id'], 'invf_recipe_component_item_unique');
        });

        Schema::create('invf_recipe_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_no', 96)->unique();
            $table->foreignId('recipe_version_id')->constrained('invf_recipe_versions');
            $table->foreignId('organization_id')->constrained('inv_organizations');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->string('mode', 8)->default('mts');
            $table->string('status', 24)->default('planned');
            $table->decimal('planned_qty', 24, 6);
            $table->decimal('actual_output_qty', 24, 6)->nullable();
            $table->decimal('actual_component_cost', 24, 6)->nullable();
            $table->decimal('output_unit_cost', 24, 6)->nullable();
            $table->foreignId('output_batch_id')->nullable()->constrained('inv_batches');
            $table->foreignId('source_document_id')->nullable()->constrained('inv_documents');
            $table->foreignId('source_line_id')->nullable()->constrained('inv_document_lines');
            $table->foreignId('consumption_document_id')->nullable()->constrained('inv_documents');
            $table->foreignId('receipt_document_id')->nullable()->constrained('inv_documents');
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['source_document_id', 'source_line_id'], 'invf_recipe_batch_mto_source_unique');
            $table->index(['organization_id', 'status', 'created_at'], 'invf_recipe_batch_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invf_recipe_batches');
        Schema::dropIfExists('invf_recipe_components');
        Schema::dropIfExists('invf_recipe_versions');
        Schema::dropIfExists('invf_recipes');
    }
};
