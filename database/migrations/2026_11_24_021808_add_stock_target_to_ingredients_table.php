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
            // How much to stock up to: always an amount (with an optional unit), never a stock level.
            $table->decimal('target_amount', 10, 3)->nullable()->after('restock_level');
            $table->string('target_unit', 16)->nullable()->after('target_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['target_amount', 'target_unit']);
        });
    }
};
