<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Loads the demo: a sample kitchen under the login test@example.com / password.
     * See `DemoKitchenSeeder`.
     *
     * This seeder must not use `WithoutModelEvents`: it would switch model events off
     * for the demo too, and shopping list entries, purchase lines and recipe lines
     * link themselves to ingredients by name when they're saved.
     */
    public function run(): void
    {
        $this->call(DemoKitchenSeeder::class);
    }
}
