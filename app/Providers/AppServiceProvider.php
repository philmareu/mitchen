<?php

namespace App\Providers;

use App\Listeners\RecordAiUsage;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request, so the listener can time each AI call from start to finish.
        $this->app->singleton(RecordAiUsage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Uploads wait on this server even when photos are kept on S3: Quick update and Quick add
        // read them from a local path, and Quick update's are never kept.
        config(['livewire.temporary_file_upload.disk' => 'local']);

        // Where the cook approves an MCP client, such as the Claude app, signing in to Mitchen.
        Passport::authorizationView('mcp.authorize');
    }
}
