<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inv_documents', function (Blueprint $table): void {
            $table->unique('reversal_of_id', 'inv_document_reversal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inv_documents', function (Blueprint $table): void {
            $table->dropUnique('inv_document_reversal_unique');
        });
    }
};
