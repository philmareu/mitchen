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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Where it's from: "Grandma's card", "back of the masa bag", a URL.
            $table->string('source')->nullable();
            // Servings, or how many it makes ("16" tortillas).
            $table->unsignedSmallInteger('servings')->nullable();
            // A list of steps, in order.
            $table->json('steps')->nullable();
            $table->text('notes')->nullable();
            // Paths on the local (private) disk: the original card or page.
            $table->json('photos')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
