<?php

namespace Database\Seeders;

use App\Actions\AddRecipe;
use App\Actions\UpdateRecipe;
use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\EquipmentCategory;
use App\Enums\IngredientCategory as Category;
use App\Enums\Protein;
use App\Enums\Sourcing;
use App\Enums\StorageLocation as Location;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\SubstituteDraft;
use App\Support\VariantDraft;
use Illuminate\Database\Seeder;

/**
 * A lived-in kitchen to click around: staples at every stock level, a few
 * one-off ingredients, some core items that need restocking (one by its own
 * restock point, two with a stock target that says how much to buy), a short
 * shopping list, a few past shops with prices, some
 * equipment (without photos), a few ingredients that take planning to get, and
 * recipes at every stage from ready to cook to needing equipment: one with a
 * substitute and a variant, one with variants, and one with a cook log and a
 * second version.
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
            ['Lemon juice', Category::Produce, false, Location::Fridge, 'half a bottle', 30],
        ];

        $restockPoints = [
            'Peanut butter' => '¼ jar',
            'Jasmine rice' => '1 lb',
            'Kosher salt' => 'out',
            'Butter' => '2 sticks',
        ];

        $stockTargets = [
            'Olive oil' => '2 bottles',
            'Jasmine rice' => '5 lb',
            'Butter' => '6 sticks',
        ];

        foreach ($kitchen as [$name, $category, $isCore, $location, $quantity, $expiresIn]) {
            $ingredient = Ingredient::firstOrCreate(['name' => $name], [
                'category' => $category,
                'is_core' => $isCore,
                'restock_point' => $restockPoints[$name] ?? null,
                'stock_target' => $stockTargets[$name] ?? null,
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

        // Bought hard, ripening on the counter.
        Ingredient::firstOrCreate(['name' => 'Avocados'], ['category' => Category::Produce])
            ->inventoryItems()
            ->firstOrCreate(['location' => Location::Counter], ['quantity' => '4', 'ready_on' => now()->addDays(3)]);

        ShoppingListItem::firstOrCreate(['name' => 'Lemons'], ['quantity' => '3', 'category' => Category::Produce]);
        ShoppingListItem::firstOrCreate(['name' => 'Paper towels']);

        $this->purchaseHistory();
        $this->equipment();
        $this->leadTimes();
        $this->recipes();
    }

    /**
     * Ingredients that take planning to get more of.
     */
    private function leadTimes(): void
    {
        // [name, category, sourcing, lead days, months in season]
        $ingredients = [
            ['Gochujang', Category::OilsAndCondiments, Sourcing::HardToFind, 5, null],
            ['Pickled red onions', Category::CannedAndJarred, Sourcing::MakeAhead, 3, null],
            ['Blueberries', Category::Produce, Sourcing::Seasonal, null, [6, 7, 8]],
        ];

        foreach ($ingredients as [$name, $category, $sourcing, $leadDays, $season]) {
            Ingredient::updateOrCreate(['name' => $name], [
                'category' => $category,
                'sourcing' => $sourcing,
                'lead_days' => $leadDays,
                'season' => $season,
            ]);
        }
    }

    private function equipment(): void
    {
        // [name, category, brand, size, count]
        $equipment = [
            ['Stand mixer', EquipmentCategory::Appliances, 'KitchenAid Artisan', '5 qt', 1],
            ['Slow cooker', EquipmentCategory::Appliances, 'Crock-Pot', '6 qt', 1],
            ['Cast iron skillet', EquipmentCategory::Cookware, 'Lodge', '10 in', 1],
            ['Dutch oven', EquipmentCategory::Cookware, null, '5 qt', 1],
            ['Sheet pan', EquipmentCategory::Bakeware, null, 'Half sheet', 2],
            ['Chef\'s knife', EquipmentCategory::Knives, null, '8 in', 1],
            ['Ladle', EquipmentCategory::Utensils, null, null, 1],
            ['Potato ricer', EquipmentCategory::Tools, null, null, 1],
            ['Tortilla press', EquipmentCategory::Tools, null, '8 in', 1],
            ['Mixing bowls', EquipmentCategory::Prep, null, null, 3],
        ];

        foreach ($equipment as [$name, $category, $brand, $size, $count]) {
            Equipment::firstOrCreate(['name' => $name], [
                'category' => $category,
                'brand' => $brand,
                'size' => $size,
                'count' => $count,
            ]);
        }
    }

    /**
     * Recipes ready to cook, a quick shop away (salsa, tortillas), a bigger shop away (the
     * stew), and one to plan ahead for (tacos with homemade pickled onions).
     */
    private function recipes(): void
    {
        $recipes = [
            new RecipeDraft(
                name: 'Breakfast for dinner',
                cuisine: 'American', course: Course::Breakfast, protein: Protein::Eggs, diets: [Diet::Vegetarian], tags: ['Weeknight'],
                servings: 3,
                ingredients: [
                    new RecipeLine('Eggs', '6'),
                    new RecipeLine('Milk', '2 tbsp'),
                    new RecipeLine('Butter', '1 tbsp'),
                    new RecipeLine('Kosher salt', 'a pinch'),
                    new RecipeLine('Bread', '4 slices', 'toasted', isOptional: true, category: Category::Bakery),
                ],
                equipment: ['Cast iron skillet'],
                steps: [
                    'Whisk the eggs with the milk and salt.',
                    'Melt the butter in the skillet over medium-low heat, add the eggs and stir slowly until just set.',
                ],
                notes: 'Nothing to do ahead. Add bacon or potatoes when there are some.',
            ),
            new RecipeDraft(
                name: 'Corn tortillas',
                cuisine: 'Mexican', course: Course::Bread, diets: [Diet::Vegan, Diet::GlutenFree, Diet::DairyFree],
                source: 'Back of the masa bag',
                servings: 16,
                ingredients: [
                    new RecipeLine('Masa harina', '2 cups', category: Category::Baking),
                    new RecipeLine('Water', '1½ cups', 'warm', category: Category::Other),
                    new RecipeLine('Kosher salt', '½ tsp'),
                ],
                equipment: ['Tortilla press', 'Cast iron skillet'],
                steps: [
                    'Mix the masa harina, water and salt into a soft dough. Cover and rest 10 minutes.',
                    'Roll into 16 balls and press each between two sheets of plastic.',
                    'Cook on a hot dry skillet about 1 minute a side, until spotted.',
                ],
            ),
            new RecipeDraft(
                name: 'Grandma\'s beef stew',
                cuisine: 'American', course: Course::Main, protein: Protein::Beef, diets: [Diet::DairyFree], tags: ['Freezes well'],
                source: 'Grandma\'s card',
                servings: 6,
                ingredients: [
                    new RecipeLine('Beef chuck', '2 lb', 'in 1 inch cubes', category: Category::MeatAndSeafood),
                    new RecipeLine('All-purpose flour', '2 tbsp'),
                    new RecipeLine('Yellow onions', '1', 'chopped'),
                    new RecipeLine('Garlic', '3 cloves', 'minced'),
                    new RecipeLine('Carrots', '3', 'sliced', category: Category::Produce),
                    new RecipeLine('Potatoes', '1½ lb', 'cubed', category: Category::Produce),
                    new RecipeLine('Beef broth', '4 cups', category: Category::CannedAndJarred),
                ],
                equipment: ['Slow cooker'],
                steps: [
                    'Defrost the beef in the fridge the night before.',
                    'Toss the beef with the flour and brown it in batches.',
                    'Put everything in the slow cooker and cook on low 8 hours.',
                ],
                notes: 'Start it before work.',
            ),
            new RecipeDraft(
                name: 'Coconut chicken curry',
                cuisine: 'Thai', course: Course::Main, protein: Protein::Chicken, diets: [Diet::GlutenFree, Diet::DairyFree], tags: ['Weeknight'],
                servings: 4,
                ingredients: [
                    new RecipeLine('Chicken thighs', '2 lb', 'cut up'),
                    new RecipeLine('Coconut milk', '1 can'),
                    new RecipeLine('Garlic', '2 cloves'),
                    new RecipeLine('Ground cumin', '1 tsp'),
                    new RecipeLine('Jasmine rice', '1½ cups'),
                    new RecipeLine('Fresh basil', 'a handful', isOptional: true),
                ],
                equipment: ['Dutch oven'],
                steps: [
                    'Brown the chicken in the Dutch oven, then add the garlic and cumin for a minute.',
                    'Add the coconut milk and simmer 20 minutes. Serve over rice with basil.',
                ],
            ),
            new RecipeDraft(
                name: 'Buttermilk pancakes',
                cuisine: 'American', course: Course::Breakfast, diets: [Diet::Vegetarian],
                source: 'Mom\'s card',
                servings: 4,
                ingredients: [
                    new RecipeLine('All-purpose flour', '1½ cups'),
                    new RecipeLine('Sugar', '2 tbsp', category: Category::Baking),
                    new RecipeLine('Baking soda', '1 tsp', category: Category::Baking),
                    new RecipeLine('Kosher salt', '½ tsp'),
                    new RecipeLine('Buttermilk', '1½ cups', category: Category::DairyAndEggs),
                    new RecipeLine('Eggs', '1'),
                    new RecipeLine('Butter', '3 tbsp', 'melted'),
                ],
                substitutes: [
                    new SubstituteDraft('Buttermilk', [new RecipeLine('Milk', '1½ cups'), new RecipeLine('Lemon juice', '1½ tbsp')], 'Stir and let it sit 5 minutes.'),
                ],
                variants: [
                    new VariantDraft('Blueberry', [new RecipeLine('Blueberries', '1 cup', category: Category::Produce)], 'Drop the berries onto each pancake once it\'s on the griddle.'),
                ],
                equipment: ['Cast iron skillet'],
                steps: [
                    'Whisk the flour, sugar, baking soda and salt.',
                    'Whisk the buttermilk, egg and butter, then stir into the dry ingredients until just mixed. Lumps are fine.',
                    'Cook ¼ cup at a time on a buttered skillet over medium heat, flipping when bubbles form.',
                ],
            ),
            new RecipeDraft(
                name: 'Fresh salsa',
                cuisine: 'Mexican', course: Course::Sauce, diets: [Diet::Vegan, Diet::GlutenFree, Diet::DairyFree],
                servings: 6,
                ingredients: [
                    new RecipeLine('Tomatoes', '4', 'diced', category: Category::Produce),
                    new RecipeLine('Yellow onions', '½', 'finely diced'),
                    new RecipeLine('Jalapeño', '1', 'seeded and minced', category: Category::Produce),
                    new RecipeLine('Garlic', '1 clove', 'minced'),
                    new RecipeLine('Kosher salt', '½ tsp'),
                    new RecipeLine('Cilantro', '¼ cup', 'chopped', isOptional: true, category: Category::Produce),
                ],
                variants: [
                    new VariantDraft('Hot', [new RecipeLine('Jalapeño', '2', 'minced with the seeds'), new RecipeLine('Habanero', '1', 'minced', category: Category::Produce)], 'Wear gloves for the habanero.'),
                    new VariantDraft('Kid friendly', [new RecipeLine('Jalapeño', isLeftOut: true)]),
                ],
                steps: [
                    'Stir everything together.',
                    'Let it sit 15 minutes, then taste for salt.',
                ],
            ),
            new RecipeDraft(
                name: 'Black bean tacos',
                cuisine: 'Mexican', course: Course::Main, protein: Protein::Beans, diets: [Diet::Vegetarian], tags: ['Weeknight'],
                servings: 4,
                ingredients: [
                    new RecipeLine('Black beans', '1 can', 'drained', category: Category::CannedAndJarred),
                    new RecipeLine('Ground cumin', '1 tsp'),
                    new RecipeLine('Corn tortillas', '8', category: Category::Bakery),
                    new RecipeLine('Pickled red onions', '½ cup'),
                    new RecipeLine('Cotija', '¼ cup', 'crumbled', isOptional: true, category: Category::DairyAndEggs),
                    new RecipeLine('Limes', '1', 'in wedges', category: Category::Produce),
                ],
                equipment: ['Cast iron skillet'],
                steps: [
                    'Warm the beans with the cumin and a splash of water, mashing a few.',
                    'Char the tortillas in the dry skillet.',
                    'Fill with the beans, pickled onions and cotija, with lime to squeeze over.',
                ],
                notes: 'The pickled onions take 3 days: make a jar the weekend before.',
            ),
        ];

        foreach ($recipes as $draft) {
            if (! Recipe::query()->where('name', $draft->name)->exists()) {
                app(AddRecipe::class)->handle($draft);
            }
        }

        $this->recipeWithHistory();
    }

    /**
     * Pickled red onions: made once, too sharp, so version 2 has more salt and less vinegar;
     * made again, with a note for next time still waiting.
     */
    private function recipeWithHistory(): void
    {
        if (Recipe::query()->where('name', 'Pickled red onions')->exists()) {
            return;
        }

        $draft = fn (string $vinegar, string $salt): RecipeDraft => new RecipeDraft(
            name: 'Pickled red onions',
            cuisine: 'Mexican',
            course: Course::Sauce,
            diets: [Diet::Vegan, Diet::GlutenFree, Diet::DairyFree],
            servings: 1,
            ingredients: [
                new RecipeLine('Red onion', '1 large', 'thinly sliced', category: Category::Produce),
                new RecipeLine('Apple cider vinegar', $vinegar, category: Category::OilsAndCondiments),
                new RecipeLine('Water', '½ cup'),
                new RecipeLine('Sugar', '1 tbsp', category: Category::Baking),
                new RecipeLine('Kosher salt', $salt),
            ],
            equipment: ['Mason jar'],
            steps: [
                'Pack the onion into the jar.',
                'Warm the vinegar, water, sugar and salt until dissolved, pour over the onion and close the jar.',
                'Refrigerate at least a day. Best after three.',
            ],
            notes: 'Keeps about 2 weeks in the fridge.',
        );

        $recipe = app(AddRecipe::class)->handle($draft('1 cup', '1 tsp'));
        $recipe->forceFill(['created_at' => now()->subDays(20)])->save();

        $first = $recipe->cookLogs()->create([
            'cooked_on' => today()->subDays(14),
            'thoughts' => 'Tasted on day three: too sharp and a little flat.',
            'next_time' => 'More salt, a bit less vinegar.',
        ]);

        app(UpdateRecipe::class)
            ->handle($recipe, $draft('¾ cup', '1½ tsp'), 'More salt and a little less vinegar: the first batch was too sharp.', [$first->id])
            ?->forceFill(['created_at' => now()->subDays(10)])
            ->save();

        $recipe->cookLogs()->create([
            'cooked_on' => today()->subDays(5),
            'thoughts' => 'Much better. Gone in a week.',
            'next_time' => 'Try a few black peppercorns in the jar.',
        ]);
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
