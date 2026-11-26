<?php

use App\Models\User;
use Database\Seeders\DemoKitchenSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;

beforeEach(function () {
    // Keys set in the environment, so installing doesn't write new ones to storage.
    config(['passport.private_key' => 'a-private-key', 'passport.public_key' => 'a-public-key']);
});

it('installs and creates the first login', function () {
    $this->artisan('mitchen:install', ['--name' => 'Phil', '--email' => 'phil@example.com', '--password' => 'secret-password'])
        ->expectsOutputToContain(route('mcp'))
        ->assertSuccessful();

    expect(User::sole())->name->toBe('Phil')
        ->and(Auth::validate(['email' => 'phil@example.com', 'password' => 'secret-password']))->toBeTrue();
});

it('writes the keys Claude signs in with when there are none', function () {
    config(['passport.private_key' => null, 'passport.public_key' => null]);
    $directory = storage_path('framework/testing/passport-keys');
    File::ensureDirectoryExists($directory);
    Passport::loadKeysFrom($directory);

    try {
        $this->artisan('mitchen:install', ['--name' => 'Phil', '--email' => 'phil@example.com', '--password' => 'secret-password'])
            ->assertSuccessful();

        expect(File::get($directory.'/oauth-private.key'))->toContain('PRIVATE KEY')
            ->and(File::get($directory.'/oauth-public.key'))->toContain('PUBLIC KEY');

        // A second run leaves the keys alone: tokens signed with them keep working.
        $privateKey = File::get($directory.'/oauth-private.key');

        $this->artisan('mitchen:install', ['--name' => 'Sam', '--email' => 'sam@example.com', '--password' => 'secret-password'])
            ->assertSuccessful();

        expect(File::get($directory.'/oauth-private.key'))->toBe($privateKey);
    } finally {
        Passport::$keyPath = null;
        File::deleteDirectory($directory);
    }
});

it('refuses a short password or an email that is already a login', function (array $options) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('mitchen:install', ['--name' => 'Someone', ...$options])->assertFailed();

    expect(User::count())->toBe(1);
})->with([
    'short password' => [['--email' => 'new@example.com', '--password' => 'short']],
    'email in use' => [['--email' => 'taken@example.com', '--password' => 'secret-password']],
]);

it('will not add a real login beside the demo login', function () {
    User::factory()->create(['email' => DemoKitchenSeeder::EMAIL]);

    $this->artisan('mitchen:install', ['--name' => 'Someone', '--email' => 'new@example.com', '--password' => 'secret-password'])
        ->expectsOutputToContain('php artisan migrate:fresh')
        ->assertFailed();

    expect(User::count())->toBe(1);
});
