<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invr_product_families', function (Blueprint $table): void {
            $table->id();
            $table->string('base_sku', 64)->unique();
            $table->string('base_name');
            $table->foreignId('item_category_id')->constrained('inv_item_categories');
            $table->foreignId('base_uom_id')->constrained('inv_uoms');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invr_variant_axes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->constrained('invr_product_families')->cascadeOnDelete();
            $table->string('name', 96);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_family_id', 'name'], 'invr_family_axis_name_unique');
        });

        Schema::create('invr_variant_axis_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_axis_id')->constrained('invr_variant_axes')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('value', 96);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['variant_axis_id', 'code'], 'invr_axis_value_code_unique');
        });

        Schema::create('invr_item_variant_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->constrained('invr_product_families')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->string('combination_key', 64);
            $table->timestamps();
            $table->unique('item_id', 'invr_variant_item_unique');
            $table->unique(['product_family_id', 'combination_key'], 'invr_family_combination_unique');
        });

        Schema::create('invr_item_variant_link_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_variant_link_id')->constrained('invr_item_variant_links')->cascadeOnDelete();
            $table->foreignId('variant_axis_value_id')->constrained('invr_variant_axis_values');
            $table->unique(['item_variant_link_id', 'variant_axis_value_id'], 'invr_link_axis_value_unique');
        });

        Schema::create('invr_item_consignment_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('location_id')->nullable()->constrained('inv_storage_locations');
            $table->unsignedBigInteger('location_scope_key')->default(0);
            $table->string('supplier_party_type');
            $table->string('supplier_party_id', 128);
            $table->decimal('reference_unit_cost', 24, 6)->nullable();
            $table->string('settlement_periodicity', 16)->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['item_id', 'location_scope_key'], 'invr_consignment_item_scope_unique');
        });

        Schema::create('invr_consignment_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_line_id')->constrained('inv_document_lines');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('consignment_term_id')->constrained('invr_item_consignment_terms');
            $table->string('supplier_party_type');
            $table->string('supplier_party_id', 128);
            $table->decimal('qty_sold', 24, 6);
            $table->date('sale_date');
            $table->string('periodicity', 16);
            $table->string('status', 24)->default('pending');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique('document_line_id', 'invr_settlement_document_line_unique');
            $table->index(['status', 'sale_date'], 'invr_settlement_status_date_idx');
            $table->index(['supplier_party_type', 'supplier_party_id', 'status'], 'invr_settlement_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invr_consignment_settlements');
        Schema::dropIfExists('invr_item_consignment_terms');
        Schema::dropIfExists('invr_item_variant_link_values');
        Schema::dropIfExists('invr_item_variant_links');
        Schema::dropIfExists('invr_variant_axis_values');
        Schema::dropIfExists('invr_variant_axes');
        Schema::dropIfExists('invr_product_families');
    }
};
