<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inva_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_no', 96)->unique();
            $table->foreignId('item_id')->constrained('inv_items');
            $table->foreignId('serial_id')->constrained('inv_serials');
            $table->foreignId('warehouse_id')->constrained('inv_organizations');
            $table->foreignId('reservation_id')->unique()->constrained('inv_reservations');
            $table->string('borrower_type');
            $table->string('borrower_id', 128);
            $table->string('status', 24)->default('active');
            $table->timestamp('checked_out_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['borrower_type', 'borrower_id', 'status'], 'inva_checkout_borrower_idx');
            $table->index(['status', 'due_at'], 'inva_checkout_due_idx');
        });

        // A row exists only while the serial is allocated. The portable unique
        // key is the database-level guarantee against concurrent double checkout.
        Schema::create('inva_active_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serial_id')->unique()->constrained('inv_serials');
            $table->foreignId('checkout_id')->unique()->constrained('inva_checkouts')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inva_active_allocations');
        Schema::dropIfExists('inva_checkouts');
    }
};
