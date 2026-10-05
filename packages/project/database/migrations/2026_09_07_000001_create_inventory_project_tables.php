<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invp_project_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('allocation_no', 96)->unique();
            $table->string('project_type');
            $table->string('project_id', 128);
            $table->foreignId('site_id')->constrained('inv_organizations');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('reservation_id')->unique()->constrained('inv_reservations');
            $table->foreignId('source_allocation_id')->nullable()->constrained('invp_project_allocations');
            $table->string('kind', 24)->default('initial');
            $table->string('status', 24)->default('active');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['project_type', 'project_id', 'site_id'], 'invp_allocation_project_site_idx');
            $table->index(['item_id', 'warehouse_id', 'status'], 'invp_allocation_stock_idx');
        });

        Schema::create('invp_project_reallocations', function (Blueprint $table): void {
            $table->id();
            $table->string('reallocation_no', 96)->unique();
            $table->foreignId('source_allocation_id')->constrained('invp_project_allocations');
            $table->foreignId('destination_allocation_id')->unique()->constrained('invp_project_allocations');
            $table->decimal('qty', 24, 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invp_project_reallocations');
        Schema::dropIfExists('invp_project_allocations');
    }
};
