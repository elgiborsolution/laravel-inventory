<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inv_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->string('batch_no', 96);
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 24)->default('available');
            $table->timestamps();
            $table->unique(['item_id', 'batch_no'], 'inv_batch_item_number_unique');
            $table->index(['item_id', 'expires_at', 'status'], 'inv_batch_fefo_idx');
        });

        Schema::create('inv_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('warehouse_id')->nullable()->constrained('inv_organizations');
            $table->foreignId('storage_location_id')->nullable()->constrained('inv_storage_locations');
            $table->string('serial_no', 128)->unique();
            $table->string('status', 24)->default('in_stock');
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'status'], 'inv_serial_item_warehouse_status_idx');
        });

        Schema::create('inv_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('trackable_type');
            $table->unsignedBigInteger('trackable_id');
            $table->string('type', 48);
            $table->string('number', 128);
            $table->string('issuing_authority')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
            $table->index(['trackable_type', 'trackable_id', 'type'], 'inv_certificate_trackable_idx');
            $table->unique(['trackable_type', 'trackable_id', 'type', 'number'], 'inv_certificate_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_certificates');
        Schema::dropIfExists('inv_serials');
        Schema::dropIfExists('inv_batches');
    }
};
