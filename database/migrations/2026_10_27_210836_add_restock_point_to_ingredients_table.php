<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Stored like an inventory quantity: an amount (with an optional unit) or a stock level.
            $table->decimal('restock_amount', 10, 3)->nullable()->after('is_core');
            $table->string('restock_unit', 16)->nullable()->after('restock_amount');
            $table->string('restock_level', 16)->nullable()->after('restock_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['restock_amount', 'restock_unit', 'restock_level']);
        });
    }
};
