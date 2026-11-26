<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoKitchenSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('mitchen:install
    {--name= : Your name}
    {--email= : The email you will sign in with}
    {--password= : Your password (min. 8 characters)}')]
#[Description('Set up the database, the keys Claude signs in with, and your first login')]
class MitchenInstallCommand extends Command
{
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        if (User::query()->where('email', DemoKitchenSeeder::EMAIL)->exists()) {
            $this->components->error(sprintf(
                'This database holds the demo, and its login (%s) has a password anyone can look up. Run `php artisan migrate:fresh` to delete the demo, then run this command again.',
                DemoKitchenSeeder::EMAIL,
            ));

            return self::FAILURE;
        }

        if (! $this->hasPassportKeys() && $this->call('passport:keys') !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Create the account you will sign in with.');

        $name = $this->option('name') ?? text('Name', required: true);
        $email = $this->option('email') ?? text('Email address', required: true, validate: $this->emailError(...));
        $password = $this->option('password') ?? password('Password', required: true, validate: $this->passwordError(...), hint: 'At least 8 characters.');

        // Values given as options skip the prompts, so check them here too.
        if ($error = $this->emailError($email) ?? $this->passwordError($password)) {
            $this->components->error($error);

            return self::FAILURE;
        }

        $status = $this->call('make:filament-user', [
            '--name' => $name,
            '--email' => $email,
            '--password' => $password,
        ]);

        if ($status === self::SUCCESS) {
            $this->components->info(sprintf('Done. To talk to your kitchen, connect Claude to %s.', route('mcp')));
        }

        return $status;
    }

    /**
     * The keys that sign the tokens an MCP client gets: set in the environment, or
     * the files `passport:keys` writes to storage.
     */
    private function hasPassportKeys(): bool
    {
        if (filled(config('passport.private_key')) && filled(config('passport.public_key'))) {
            return true;
        }

        return file_exists(Passport::keyPath('oauth-private.key')) && file_exists(Passport::keyPath('oauth-public.key'));
    }

    private function emailError(string $email): ?string
    {
        return $this->firstError($email, ['required', 'email', 'max:255', Rule::unique(User::class, 'email')], 'email');
    }

    private function passwordError(string $password): ?string
    {
        return $this->firstError($password, ['required', Password::defaults()], 'password');
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function firstError(string $value, array $rules, string $field): ?string
    {
        return Validator::make([$field => $value], [$field => $rules])->errors()->first($field) ?: null;
    }
}
