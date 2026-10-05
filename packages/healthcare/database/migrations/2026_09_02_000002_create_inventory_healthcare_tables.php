<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invh_recalls', function (Blueprint $table): void {
            $table->id();
            $table->string('recall_no', 96)->unique();
            $table->foreignId('batch_id')->constrained('inv_batches');
            $table->string('status', 24)->default('active');
            $table->text('reason');
            $table->timestamp('recalled_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'status'], 'invh_recall_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invh_recalls');
    }
};
