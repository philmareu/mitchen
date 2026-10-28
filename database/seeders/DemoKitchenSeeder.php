<?php

namespace Database\Seeders;

use App\Enums\IngredientCategory as Category;
use App\Enums\StorageLocation as Location;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\ShoppingListItem;
use App\Models\Store;
use Illuminate\Database\Seeder;

/**
 * A lived-in kitchen to click around: staples at every stock level, a few
 * one-off ingredients, some core items that need restocking (one by its own
 * restock point), a short shopping list, and a few past shops with prices.
 */
class DemoKitchenSeeder extends Seeder
{
    public function run(): void
    {
        // [name, category, core?, location, quantity, expires in days]
        $kitchen = [
            ['Peanut butter', Category::CannedAndJarred, true, Location::Pantry, 'half a jar', null],
            ['Olive oil', Category::OilsAndCondiments, true, Location::Pantry, 'low', null],
            ['Soy sauce', Category::OilsAndCondiments, true, Location::Fridge, 'out', null],
            ['Jasmine rice', Category::GrainsAndPasta, true, Location::Pantry, '2 lb', null],
            ['All-purpose flour', Category::Baking, true, Location::Pantry, 'about half', null],
            ['Kosher salt', Category::SpicesAndHerbs, true, Location::SpiceRack, 'plenty', null],
            ['Ground cumin', Category::SpicesAndHerbs, true, Location::SpiceRack, 'almost out', null],
            ['Eggs', Category::DairyAndEggs, true, Location::Fridge, 'a dozen', 18],
            ['Butter', Category::DairyAndEggs, true, Location::Fridge, '2 sticks', 30],
            ['Milk', Category::DairyAndEggs, true, Location::Fridge, 'half a gallon', 5],
            ['Garlic', Category::Produce, true, Location::Counter, '1 head', null],
            ['Yellow onions', Category::Produce, true, null, null, null],
            ['Chicken thighs', Category::MeatAndSeafood, false, Location::Freezer, '2 lb', 60],
            ['Coconut milk', Category::CannedAndJarred, false, Location::Pantry, '2 cans', null],
            ['Fresh basil', Category::Produce, false, Location::Fridge, 'a bunch', 3],
            ['Gochujang', Category::OilsAndCondiments, false, Location::Fridge, 'three quarters of a jar', 120],
        ];

        $restockPoints = [
            'Peanut butter' => '¼ jar',
            'Jasmine rice' => '1 lb',
            'Kosher salt' => 'out',
            'Butter' => '2 sticks',
        ];

        foreach ($kitchen as [$name, $category, $isCore, $location, $quantity, $expiresIn]) {
            $ingredient = Ingredient::firstOrCreate(['name' => $name], [
                'category' => $category,
                'is_core' => $isCore,
                'restock_point' => $restockPoints[$name] ?? null,
            ]);

            if ($location === null) {
                continue;
            }

            $ingredient->inventoryItems()->create([
                'location' => $location,
                'quantity' => $quantity,
                'expires_on' => $expiresIn ? now()->addDays($expiresIn) : null,
            ]);
        }

        ShoppingListItem::firstOrCreate(['name' => 'Lemons'], ['quantity' => '3', 'category' => Category::Produce]);
        ShoppingListItem::firstOrCreate(['name' => 'Paper towels']);

        $this->purchaseHistory();
    }

    /**
     * A few past shops at two stores, so prices show on the shopping list and ingredients.
     */
    private function purchaseHistory(): void
    {
        if (Purchase::query()->exists()) {
            return;
        }

        $costco = Store::firstOrCreate(['name' => 'Costco'], ['notes' => 'Rice, butter and olive oil in bulk.']);
        $traderJoes = Store::firstOrCreate(['name' => 'Trader Joe\'s']);

        // [store, days ago, [[name, quantity, price]]]
        $shops = [
            [$costco, 38, [['Jasmine rice', '10 lb', 18.99], ['Butter', '8 sticks', 9.49], ['Olive oil', '2 l', 21.99], ['Chicken thighs', '4.2 lb', 14.66]]],
            [$traderJoes, 17, [['Peanut butter', '1 jar', 3.49], ['Milk', '1 gallon', 4.29], ['Eggs', '12', 3.99], ['Coconut milk', '2 cans', 3.58], ['Gochujang', '1 jar', 4.49]]],
            [$traderJoes, 5, [['Milk', '1 gallon', 4.49], ['Eggs', '12', 4.29], ['Garlic', '3 heads', 1.99], ['Fresh basil', 'a bunch', 2.49]]],
        ];

        foreach ($shops as [$store, $daysAgo, $lines]) {
            $purchase = $store->purchases()->create(['purchased_on' => today()->subDays($daysAgo)]);

            foreach ($lines as [$name, $quantity, $price]) {
                $purchase->items()->create(['name' => $name, 'quantity' => $quantity, 'price' => $price]);
            }

            $purchase->addUpMissingTotal();
        }
    }
}
