<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The log of calls to the Laravel AI SDK, which Mitchen no longer makes: an assistant
     * works with the kitchen over the MCP server instead.
     */
    public function up(): void
    {
        Schema::dropIfExists('ai_calls');
    }

    /**
     * Nothing to bring back: the logged calls are gone with the table, and nothing reads it.
     */
    public function down(): void
    {
        //
    }
};
