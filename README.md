# Mitchen

**A self-hosted kitchen assistant that knows what's in your kitchen.**

Mitchen keeps track of your ingredients and how much of each you have, without making you weigh anything. Type `half a jar`, `2 lb`, `a couple cans` or just `low`, and it understands. It knows which ingredients are staples you always keep on hand, so it can tell you what to restock, keep a shopping list, and remind you what to use before it goes off.

Your recipes live alongside it, so each one shows what's on hand, what's missing and what to buy. It's the foundation for a bigger idea: meal planning and an AI assistant that cooks with the ingredients and equipment you actually have.

## Features

- **Write quantities the way you'd say them.** One box takes exact amounts (`500g`, `1 1/2 cups`, `1½ cups`), containers (`half a jar`, `3 cans`, `two thirds of a bag`) or a rough level (`plenty`, `about half`, `running low`, `out`). The form shows what it understood as you type, and stores the result as structured data.
- **Core vs. one-off ingredients.** Mark the staples you always keep on hand. When a core ingredient is used up it stays in your inventory as *out* and goes on the restock list. A one-off bought for a single recipe just disappears.
- **Restock list, with restock points.** Core ingredients that are low, out, or not stocked at all, with a one-click "Restocked" that records what you bought. Give an ingredient its own restock point (`¼ jar`, `2 cans`, `1 lb`, or `out`) and it goes on the list when what you have adds up to that, converting between units (`12 oz` counts against `1 lb`).
- **Shopping list.** Grouped by aisle, with what you already have at home under each item. Bring in everything on the restock list in one click, add your own items, tick things off in the store, then put away what you got: check how much and where, and it's added to the inventory and comes off the list.
- **Purchase history and prices.** Every shop is recorded with its store, date, total and what each thing cost: scan the receipt, put away what you ticked off, or add one by hand. See what you spent this month, which store you shop at most, and what you last paid per unit (`$3.49/lb`, `$0.33 each`) right on the shopping list.
- **Use soon.** Anything with a use-by date in the next week.
- **Organized by place.** Pantry, fridge, freezer, spice rack and counter, with filters for core items and anything running low.
- **Quick update, powered by Claude.** Type "bought 2 lbs chicken thighs and a jar of peanut butter, out of milk, half a bag of rice left, need eggs" and Mitchen turns it into a list of changes: new supply, what's left, what's used up, what to buy. You check and edit the list before anything is saved.
- **Snap a grocery receipt.** Add a photo (or a few, for a long receipt) and Mitchen decodes lines like `BNLS SKNLS CHKN THGH 2.13 lb` into "Chicken thighs, 2.13 lb, fridge", matches them to what you already keep, reads the store, date, total and line prices for your purchase history, leaves out bags and tax, and takes what you bought off the shopping list, paper towels included (including anything you already ticked in the store, so nothing is counted twice). Same review step before saving; photos are never stored.
- **Take stock with a photo.** Photograph a shelf of labeled jars, the fridge or the pantry, and Mitchen reads the labels, counts cans and cartons, and judges how full each jar is ("⅓ jar of flour"). If a photo shows a whole shelf or fridge and something you have on record isn't in it, it asks whether that's gone rather than assuming.
- **Equipment, from photos.** Photograph your stand mixer, a shelf of pans or a drawer of utensils, or just describe them ("two half sheet pans, a 7 qt Lodge Dutch oven, the blender died"), and Mitchen lists each piece with its brand and size, matched against what you already have. Same review step before saving. Unlike receipts, these photos are kept with each piece as a picture of your own gear; crop a drawer shot down to one utensil in the editor.
- **Recipes, from a photo of the card.** Snap a handwritten recipe card, a cookbook page or the back of a package, or paste a recipe, and Mitchen reads its ingredients, equipment and steps, matching "flour" to the *All-purpose flour* you keep and adding anything new to your ingredients. Same review step before saving, and the photo is kept with the recipe. Or type one in.
- **What's in the kitchen for each recipe.** Every ingredient shows *on hand*, *running low* or *out*, and where it is (`2 lb · Fridge`); every piece of equipment, whether you have it. Put what's missing on the shopping list in one click, with the amounts the recipe calls for. Water and ice count as always on hand.
- **What to cook.** Recipes sorted by how close you are to making them: *ready to cook*, *a quick shop* (up to three things), *a bigger shop*, *plan ahead* or *needs equipment*, with what's missing. Mark an ingredient as hard to find, seasonal (with its months) or homemade with how long a batch takes, and recipes that need it are planned for rather than ruled out: "Plan ahead: Pickled red onions (make ahead, about 3 days)". If a variant gets you closer (no cilantro), it says so. The dashboard suggests what's ready or close, made longest ago first, and puts what to buy on the shopping list in one click.
- **Scale a recipe.** Halve or double it, and the amounts Mitchen can read are scaled (`1½ cups` becomes `¾ cup`); `a pinch` stays a pinch.
- **Recipes that get better.** Press *Made it* to log a batch: how it turned out and what to change next time, even days later (pickles taste right on day three). *Update from notes* has Claude work your notes into the recipe ("more salt, less vinegar" becomes `1½ tsp` salt and `¾ cup` vinegar); you check it and it's saved as a new version. Every change keeps the old version, with what changed.
- **Substitutes and variants.** A substitute stands in for one ingredient when you're out (no buttermilk: milk and lemon juice), so the recipe still counts as ready to cook. A variant is a way of making it you pick up front (mild or hot salsa, blueberry pancakes), and the ingredients and shopping list follow it. Quick add reads both off the card.
- **AI usage and cost.** Every call to Claude is logged with its model, tokens, time and estimated cost, with this month's and all-time spend at a glance.
- **Ask Claude about your kitchen.** Mitchen is an MCP server, so you can connect it to the Claude app and ask from the counter: what can I make tonight, what's on the shopping list, what should I use up? Tell it what you bought or finished and it reads the changes back, saving them once you agree. Hand it a recipe from a website and it saves it to your collection; tell it how dinner turned out and it goes in the cook log, then have it work those notes into the next version of the recipe.

## Getting started

Requirements: PHP 8.3+, Composer, Node, and MySQL (or SQLite).

```bash
git clone <this repo> mitchen && cd mitchen
cp .env.example .env            # set DB_* for your database
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate --seed      # demo kitchen + test@example.com / password
php artisan serve
```

For a real install, skip `--seed` and create your own login with `php artisan make:filament-user`.

Photos (recipe cards and equipment) are kept privately in `storage/app/private`. To keep them on S3 instead, set `FILESYSTEM_DISK=s3` and the `AWS_*` values, and give the bucket a CORS rule allowing `GET` from your app's URL so the photo fields and editor can load them.

Quick update and photo reading use the [Laravel AI SDK](https://github.com/laravel/ai) with Anthropic. Set `ANTHROPIC_API_KEY` in `.env` to turn them on; everything else works without it. Typed notes run on the fast model (`ANTHROPIC_FAST_MODEL`, Claude Haiku by default) and photos on your main model (`ANTHROPIC_MODEL`). Cost estimates use the rates in `config/ai.php`; on Claude Sonnet a receipt costs about a cent and a shelf photo about three.

## Connecting Claude

Mitchen serves an MCP server at `/mcp`. Its tools read the kitchen (what's on hand, the restock and use-soon lists, the shopping list, recipes by how close you are to making them, equipment) and update it (bought, used up, how much is left, the shopping list, new recipes, the cook log, revisions from your notes, previewed before they're saved, and equipment you forgot to list, so recipes that need it count it as there), using the same actions as the app. Claude does the understanding on its side, so these calls don't use your `ANTHROPIC_API_KEY`.

Clients sign in with OAuth through [Laravel Passport](https://laravel.com/docs/passport):

1. Run `php artisan passport:keys` once on the server (or set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`). The keys go in `storage/`, so with zero-downtime deploys (Forge) they carry over between releases.
2. In Claude, add a custom connector (Settings → Connectors) with your app's URL plus `/mcp`, such as `https://mitchen.example.com/mcp`.
3. Connect: sign in to Mitchen and approve. The connector is then available in the Claude apps too.

## How quantities work

Each inventory item stores its quantity as **either** an amount with an optional unit **or** a stock level, never both:

| You type | Stored as | Shown as |
| --- | --- | --- |
| `half a jar` | 0.5 · jar | ½ jar |
| `1 1/2 cups` | 1.5 · cup | 1½ cups |
| `500g` | 500 · g | 500 g |
| `a dozen` | 12 | 12 |
| `almost out` | low | Low |
| `0` or `empty` | out | Out |

Units cover weights, volumes and the containers food comes in (jar, can, bottle, bag, box, bunch, head, clove, stick…). Anything unrecognized is rejected with a hint instead of being saved as junk, so recipes and the AI layer can rely on the data later. The parser is `app/Support/QuantityParser.php`, and its tests in `tests/Unit` double as a list of what it understands.

## Roadmap

- [x] Ingredient catalog with core / one-off ingredients
- [x] Inventory with flexible quantities, locations and use-by dates
- [x] Restock and use-soon lists
- [x] Recipes linked to ingredients and equipment, added from a photo of the card or a note, showing what's on hand
- [x] Recipe history (a cook log and versions), substitutes and variants
- [x] Recipe suggestions: what you can make now, with a little shopping, or with time to prep ahead
- [x] Equipment, added from photos or a note
- [x] Restock points and a shopping list built from the restock list
- [ ] Meal planning, adding to the shopping list from the plan
- [x] Quick updates in plain language ("bought 2 lbs chicken, out of milk, need eggs"), reviewed before saving
- [x] Grocery receipt photos that tick off the shopping list
- [x] Stock-takes from photos of shelves, the fridge or the pantry
- [x] Stores and purchase history with prices
- [ ] Price comparison across stores, and choosing a store per shopping list item
- [x] AI usage and cost tracking
- [x] An MCP server, so Claude can read and update the kitchen
- [ ] More AI: recipes from the web, and meal ideas from what's on hand

## Development

```bash
composer dev                    # server, queue, logs and Vite together
php artisan test --compact      # Pest
vendor/bin/pint                 # code style
vendor/bin/phpstan analyse      # Larastan, level 6
```

Built with Laravel 13, Filament 5, Livewire 4 and Pest.
