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
            // How you get more: any grocery store, hard to find, seasonal or made ahead.
            $table->string('sourcing')->default('grocery')->after('always_on_hand');
            // How many days it takes to get it in or make a batch.
            $table->unsignedSmallInteger('lead_days')->nullable()->after('sourcing');
            // The months (1-12) a seasonal ingredient is in the stores.
            $table->json('season')->nullable()->after('lead_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['sourcing', 'lead_days', 'season']);
        });
    }
};
