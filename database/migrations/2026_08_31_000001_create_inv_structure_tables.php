<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inv_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('inv_organizations');
            $table->string('type', 32);
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['parent_id', 'type', 'code'], 'inv_org_parent_type_code_unique');
            $table->index(['type', 'is_active'], 'inv_org_type_active_idx');
        });

        Schema::create('inv_storage_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('inv_organizations');
            $table->foreignId('parent_id')->nullable()->constrained('inv_storage_locations');
            $table->string('type', 32);
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'parent_id', 'type', 'code'], 'inv_storage_scope_code_unique');
            $table->index(['organization_id', 'type', 'is_active'], 'inv_storage_org_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_storage_locations');
        Schema::dropIfExists('inv_organizations');
    }
};
