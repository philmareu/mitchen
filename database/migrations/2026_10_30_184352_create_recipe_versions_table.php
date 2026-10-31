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
        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // The version this change made (2 and up; version 1 is the recipe as first added).
            $table->unsignedSmallInteger('number');
            // The recipe as it was before: the version before this one.
            $table->json('previous');
            $table->json('changes');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_versions');
    }
};
