<?php

use App\Enums\Readiness;
use App\Models\CookLog;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DemoKitchenSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, int> rows per table the demo writes to
 */
function demoRowCounts(): array
{
    return collect(['users', 'ingredients', 'inventory_items', 'shopping_list_items', 'stores', 'purchases', 'purchase_items', 'equipment', 'recipes', 'recipe_ingredients', 'cook_logs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('loads the demo login and a kitchen at every stage, whatever the date', function (string $today) {
    $this->travelTo($today);

    $this->seed();

    expect(Auth::attempt(['email' => DemoKitchenSeeder::EMAIL, 'password' => DemoKitchenSeeder::PASSWORD]))->toBeTrue()
        ->and(User::count())->toBe(1)
        ->and(Ingredient::count())->toBe(40)
        ->and(Equipment::count())->toBe(10)
        ->and(Store::count())->toBe(2)
        ->and(ShoppingListItem::count())->toBe(2)
        ->and(Recipe::count())->toBe(8)
        ->and(Recipe::query()->readinessByKey()->unique()->values()->all())->toEqualCanonicalizing(Readiness::cases());

    // Every date counts from today: nothing was bought or cooked in the future.
    expect(Purchase::query()->whereDate('purchased_on', '>', today())->exists())->toBeFalse()
        ->and(CookLog::query()->whereDate('cooked_on', '>', today())->exists())->toBeFalse()
        ->and(InventoryItem::query()->whereNotNull('ready_on')->sole()->ready_on->toDateString())->toBe(today()->addDays(3)->toDateString());
})->with(['2026-01-15', '2026-07-04', '2026-12-31']);

it('refuses to load in production, even when forced', function () {
    $this->app['env'] = 'production';

    expect(fn () => $this->artisan('db:seed', ['--force' => true])->run())
        ->toThrow(RuntimeException::class, 'does not run in production');

    $this->assertDatabaseEmpty('users');
    $this->assertDatabaseEmpty('ingredients');
});

it('refuses to load when the database has another login, and leaves everything as it was', function () {
    User::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $before = demoRowCounts();

    expect(fn () => $this->artisan('db:seed', ['--class' => DemoKitchenSeeder::class])->run())
        ->toThrow(RuntimeException::class, 'already has a real login');

    expect(demoRowCounts())->toBe($before);
    $this->assertModelExists($ingredient);
    $this->assertDatabaseMissing('users', ['email' => DemoKitchenSeeder::EMAIL]);
});

it('only loads into an empty kitchen, so a second run changes nothing', function () {
    $this->seed();
    $before = demoRowCounts();

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'already has something in it');

    expect(demoRowCounts())->toBe($before);
});

it('keeps the demo login as it is when it loads into a kitchen that was emptied', function () {
    $user = User::factory()->create(['email' => DemoKitchenSeeder::EMAIL, 'password' => 'changed-password']);

    $this->seed();

    expect(User::count())->toBe(1)
        ->and(Auth::validate(['email' => $user->email, 'password' => 'changed-password']))->toBeTrue()
        ->and(Recipe::count())->toBe(8);
});
