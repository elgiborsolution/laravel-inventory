<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invw_location_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_location_id')->unique()->constrained('inv_storage_locations')->cascadeOnDelete();
            $table->string('zone', 64)->default('DEFAULT');
            $table->unsignedInteger('travel_sequence')->default(0);
            $table->decimal('capacity_qty', 24, 6)->nullable();
            $table->foreignId('dedicated_item_id')->nullable()->constrained('inv_items');
            $table->boolean('put_away_enabled')->default(true);
            $table->boolean('picking_enabled')->default(true);
            $table->timestamps();
            $table->index(['zone', 'travel_sequence'], 'invw_profile_zone_sequence_idx');
        });

        Schema::create('invw_put_away_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('item_id')->nullable()->constrained('inv_items');
            $table->string('strategy', 32);
            $table->foreignId('fixed_location_id')->nullable()->constrained('inv_storage_locations');
            $table->string('zone', 64)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['warehouse_id', 'item_id', 'is_active', 'priority'], 'invw_putaway_rule_resolve_idx');
        });

        Schema::create('invw_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('status', 24)->default('open');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('document_id')->nullable()->constrained('inv_documents');
            $table->foreignId('document_line_id')->nullable()->constrained('inv_document_lines');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('qty', 24, 6);
            $table->foreignId('from_location_id')->nullable()->constrained('inv_storage_locations');
            $table->foreignId('to_location_id')->nullable()->constrained('inv_storage_locations');
            $table->string('lpn_code', 96)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'type', 'status'], 'invw_task_work_queue_idx');
        });

        Schema::create('invw_waves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->string('code', 96)->unique();
            $table->string('status', 24)->default('planned');
            $table->timestamps();
        });

        Schema::create('invw_wave_tasks', function (Blueprint $table): void {
            $table->foreignId('wave_id')->constrained('invw_waves')->cascadeOnDelete();
            $table->foreignId('task_id')->unique()->constrained('invw_tasks')->cascadeOnDelete();
            $table->primary(['wave_id', 'task_id']);
        });

        Schema::create('invw_lpns', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('storage_location_id')->nullable()->constrained('inv_storage_locations');
            $table->string('status', 24)->default('open');
            $table->timestamps();
        });

        Schema::create('invw_lpn_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lpn_id')->constrained('invw_lpns')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('batch_id')->nullable()->constrained('inv_batches');
            $table->unsignedBigInteger('batch_scope_key')->default(0);
            $table->decimal('qty', 24, 6);
            $table->timestamps();
            $table->unique(['lpn_id', 'item_id', 'batch_scope_key'], 'invw_lpn_item_batch_unique');
        });

        Schema::create('invw_replenishment_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('source_location_id')->constrained('inv_storage_locations');
            $table->foreignId('pick_location_id')->constrained('inv_storage_locations');
            $table->decimal('minimum_qty', 24, 6);
            $table->decimal('target_qty', 24, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['item_id', 'pick_location_id'], 'invw_replenishment_item_pick_unique');
        });

        Schema::create('invw_cross_dock_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->nullable()->constrained('inv_items');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('staging_location_id')->constrained('inv_storage_locations');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['warehouse_id', 'item_id', 'is_active', 'priority'], 'invw_cross_dock_route_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invw_cross_dock_routes');
        Schema::dropIfExists('invw_replenishment_rules');
        Schema::dropIfExists('invw_lpn_contents');
        Schema::dropIfExists('invw_lpns');
        Schema::dropIfExists('invw_wave_tasks');
        Schema::dropIfExists('invw_waves');
        Schema::dropIfExists('invw_tasks');
        Schema::dropIfExists('invw_put_away_rules');
        Schema::dropIfExists('invw_location_profiles');
    }
};
