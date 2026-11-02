<?php

use App\Mcp\Servers\MitchenServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Discovery and client registration for MCP clients such as the Claude app. Registration is
// open (each client registers itself), so it's rate limited.
Route::middleware('throttle:10,1')->group(function () {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', MitchenServer::class)
    ->middleware('auth:api')
    ->name('mcp');
