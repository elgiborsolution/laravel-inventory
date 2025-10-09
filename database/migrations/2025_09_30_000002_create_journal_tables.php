<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('inv_journals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('inv_documents');
            $t->date('date');
            $t->string('memo')->nullable();
            $t->timestamps();
        });
        Schema::create('inv_journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_id')->constrained('inv_journals');
            $t->string('account');
            $t->enum('dc',['D','C']);
            $t->decimal('amount', 18, 2);
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('inv_journal_entries');
        Schema::dropIfExists('inv_journals');
    }
};
