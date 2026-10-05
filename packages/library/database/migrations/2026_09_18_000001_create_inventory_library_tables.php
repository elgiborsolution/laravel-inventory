<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invl_holds', function (Blueprint $t): void {
            $t->id();
            $t->string('hold_no', 96)->unique();
            $t->foreignId('item_id')->constrained('inv_items');
            $t->foreignId('warehouse_id')->constrained('inv_organizations');
            $t->string('patron_type');
            $t->string('patron_id', 128);
            $t->string('status', 24)->default('waiting');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('ready_until')->nullable();
            $t->timestamps();
            $t->index(['item_id', 'warehouse_id', 'status', 'created_at', 'id'], 'invl_hold_queue_idx');
        });
        Schema::create('invl_circulations', function (Blueprint $t): void {
            $t->id();
            $t->string('loan_no', 96)->unique();
            $t->foreignId('serial_id')->constrained('inv_serials');
            $t->foreignId('reservation_id')->unique()->constrained('inv_reservations');
            $t->foreignId('hold_id')->nullable()->unique()->constrained('invl_holds');
            $t->string('patron_type');
            $t->string('patron_id', 128);
            $t->timestamp('checked_out_at');
            $t->timestamp('due_at');
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamps();
        });
        Schema::create('invl_active_allocations', function (Blueprint $t): void {
            $t->foreignId('serial_id')->primary()->constrained('inv_serials');
            $t->foreignId('reservation_id')->unique()->constrained('inv_reservations');
            $t->foreignId('hold_id')->nullable()->unique()->constrained('invl_holds');
            $t->foreignId('circulation_id')->nullable()->unique()->constrained('invl_circulations');
        });
        Schema::create('invl_renewals', function (Blueprint $t): void {
            $t->id();
            $t->string('renewal_no', 96)->unique();
            $t->foreignId('circulation_id')->constrained('invl_circulations');
            $t->timestamp('previous_due_at');
            $t->timestamp('due_at');
            $t->timestamps();
        });
        Schema::create('invl_fines', function (Blueprint $t): void {
            $t->id();
            $t->string('fine_no', 96)->unique();
            $t->foreignId('circulation_id')->constrained('invl_circulations');
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->text('reason');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['invl_fines', 'invl_renewals', 'invl_active_allocations', 'invl_circulations', 'invl_holds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
