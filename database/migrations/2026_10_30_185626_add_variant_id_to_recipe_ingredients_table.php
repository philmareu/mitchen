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
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            // Set on a variant's changes: a new amount for one of the recipe's ingredients, an
            // added ingredient, or (is_left_out) one it goes without.
            $table->foreignId('variant_id')->nullable()->after('substitute_id')->constrained('recipe_variants')->cascadeOnDelete();
            $table->boolean('is_left_out')->default(false)->after('is_optional');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn('is_left_out');
        });
    }
};
