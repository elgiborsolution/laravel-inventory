<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_cards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('item_id')->index();
            $t->uuid('branch_id');
            
            // Info Transaksi
            $t->date('date');
            $t->string('document_ref');
            $t->string('document_type'); // purchase, sale, opname, dsb
            $t->string('direction'); // in, out, balance
            $t->string('description')->nullable();

            
            // Kolom Transaksi
            $t->decimal('qty', 18, 2)->default(0);
            $t->decimal('sales_price', 18, 2)->default(0); 
            $t->decimal('discount_amount', 18, 2)->default(0); 
            $t->decimal('nett_price', 18, 2)->default(0); 
            $t->decimal('total_trx', 18, 2)->default(0); 
            
            // Kolom Inventory Balance (Pre-calculated)
            $t->decimal('average_cost', 18, 2)->default(0); 
            $t->decimal('balance_qty', 18, 2)->default(0);  
            $t->decimal('balance_amount', 18, 2)->default(0); 
            
            // Kolom Sales Profitability (Pre-calculated)
            $t->decimal('cogs', 18, 2)->default(0); 
            $t->decimal('profit_unit', 18, 2)->default(0); 
            $t->decimal('profit_amount', 18, 2)->default(0); 
            $t->decimal('running_total_sales', 18, 2)->default(0); 
            $t->decimal('running_avg_sales', 18, 2)->default(0); 

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_cards');
    }
};
