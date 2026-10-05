<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inv_item_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->json('policies')->nullable();
            $table->timestamps();
        });

        Schema::create('inv_item_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_category_id')->constrained('inv_item_categories');
            $table->string('code', 64);
            $table->string('name');
            $table->timestamps();
            $table->unique(['item_category_id', 'code'], 'inv_group_category_code_unique');
        });

        Schema::create('inv_uoms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('precision')->default(6);
            $table->timestamps();
        });

        Schema::create('inv_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('inv_items', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 96)->unique();
            $table->string('name');
            $table->string('item_type', 32)->default('stock');
            $table->foreignId('item_category_id')->constrained('inv_item_categories');
            $table->foreignId('item_group_id')->nullable()->constrained('inv_item_groups');
            $table->foreignId('brand_id')->nullable()->constrained('inv_brands');
            $table->foreignId('base_uom_id')->constrained('inv_uoms');
            $table->string('costing_method', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('tracking')->nullable();
            $table->timestamps();
            $table->index(['item_type', 'is_active'], 'inv_items_type_active_idx');
        });

        Schema::create('inv_uom_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('from_uom_id')->constrained('inv_uoms');
            $table->foreignId('to_uom_id')->constrained('inv_uoms');
            $table->decimal('factor', 24, 12);
            $table->timestamps();
            $table->unique(['item_id', 'from_uom_id', 'to_uom_id'], 'inv_uom_conversion_unique');
        });

        Schema::create('inv_item_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->string('variant_code', 96);
            $table->json('attributes');
            $table->timestamps();
            $table->unique(['item_id', 'variant_code'], 'inv_item_variant_code_unique');
        });

        Schema::create('inv_reason_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('category', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inv_inventory_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('inv_organizations');
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'period_start', 'period_end'], 'inv_calendar_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_inventory_calendars');
        Schema::dropIfExists('inv_reason_codes');
        Schema::dropIfExists('inv_item_variants');
        Schema::dropIfExists('inv_uom_conversions');
        Schema::dropIfExists('inv_items');
        Schema::dropIfExists('inv_brands');
        Schema::dropIfExists('inv_uoms');
        Schema::dropIfExists('inv_item_groups');
        Schema::dropIfExists('inv_item_categories');
    }
};
