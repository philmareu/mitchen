<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Uploads wait on this server even when photos are kept on S3, so the bucket only has to
        // let the browser read photos, never write them.
        config(['livewire.temporary_file_upload.disk' => 'local']);

        // Where the cook approves an MCP client, such as the Claude app, signing in to Mitchen.
        Passport::authorizationView('mcp.authorize');
    }
}
