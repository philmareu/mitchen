# Mitchen

**A self-hosted kitchen assistant that knows what's in your kitchen.**

Mitchen keeps track of your ingredients and how much of each you have, without making you weigh anything. Type `half a jar`, `2 lb`, `a couple cans` or just `low`, and it understands. It knows which ingredients are staples you always keep on hand, so it can tell you what to restock, keep a shopping list, and remind you what to use before it goes off.

It's the foundation for a bigger idea: recipes, equipment, meal planning and an AI assistant that cooks with what you actually have.

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
- **AI usage and cost.** Every call to Claude is logged with its model, tokens, time and estimated cost, with this month's and all-time spend at a glance.

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

Quick update and photo reading use the [Laravel AI SDK](https://github.com/laravel/ai) with Anthropic. Set `ANTHROPIC_API_KEY` in `.env` to turn them on; everything else works without it. Typed notes run on the fast model (`ANTHROPIC_FAST_MODEL`, Claude Haiku by default) and photos on your main model (`ANTHROPIC_MODEL`). Cost estimates use the rates in `config/ai.php`; on Claude Sonnet a receipt costs about a cent and a shelf photo about three.

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
- [ ] Recipes linked to ingredients, with "what can I make right now?"
- [ ] Equipment
- [x] Restock points and a shopping list built from the restock list
- [ ] Meal planning, adding to the shopping list from the plan
- [x] Quick updates in plain language ("bought 2 lbs chicken, out of milk, need eggs"), reviewed before saving
- [x] Grocery receipt photos that tick off the shopping list
- [x] Stock-takes from photos of shelves, the fridge or the pantry
- [x] Stores and purchase history with prices
- [ ] Price comparison across stores, and choosing a store per shopping list item
- [x] AI usage and cost tracking
- [ ] More AI: recipe import, and meal ideas from what's on hand

## Development

```bash
composer dev                    # server, queue, logs and Vite together
php artisan test --compact      # Pest
vendor/bin/pint                 # code style
vendor/bin/phpstan analyse      # Larastan, level 6
```

Built with Laravel 13, Filament 5, Livewire 4 and Pest.
