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
        Schema::create('cook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->date('cooked_on');
            // The recipe's version when it was made.
            $table->unsignedSmallInteger('version');
            // The name of the variant made, if any; kept as text so history outlives the variant.
            $table->string('variant')->nullable();
            $table->text('thoughts')->nullable();
            $table->text('next_time')->nullable();
            // The version that took in the next-time notes; null while they wait.
            $table->unsignedSmallInteger('used_in_version')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cook_logs');
    }
};
