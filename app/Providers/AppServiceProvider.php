<?php

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // No MCP client signs in with a device code, so Passport's pages for it stay off.
        Passport::$deviceCodeGrantEnabled = false;
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

        // A client's access lasts a day and is renewed with its refresh token, so a token that
        // leaks is soon useless and a connection nobody uses for two months has to be approved again.
        Passport::tokensExpireIn(CarbonInterval::day());
        Passport::refreshTokensExpireIn(CarbonInterval::days(60));
    }
}
