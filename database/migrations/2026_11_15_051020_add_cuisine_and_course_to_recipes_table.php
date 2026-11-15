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
        Schema::table('recipes', function (Blueprint $table) {
            // Free text, spelled like the cuisines already in use ("Mexican", "Tex-Mex").
            $table->string('cuisine')->nullable()->index()->after('source');
            $table->string('course')->nullable()->index()->after('cuisine');
            $table->string('protein')->nullable()->after('course');
            // Diet values that apply (vegetarian, gluten_free...).
            $table->json('diets')->nullable()->after('protein');
            // Free-text labels: weeknight, holiday, kid favorite.
            $table->json('tags')->nullable()->after('diets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['cuisine']);
            $table->dropIndex(['course']);
            $table->dropColumn(['cuisine', 'course', 'protein', 'diets', 'tags']);
        });
    }
};
