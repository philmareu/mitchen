# Mitchen

**A self-hosted kitchen assistant that knows what's in your kitchen.**

Mitchen keeps track of your ingredients and how much of each you have, without making you weigh anything. Type `half a jar`, `2 lb`, `a couple cans` or just `low`, and it understands. It knows which ingredients are staples you always keep on hand, so it can tell you what to restock, keep a shopping list, and remind you what to use before it goes off.

Your recipes live alongside it, so each one shows what's on hand, what's missing and what to buy. Mitchen has no AI of its own: connect Claude to it and you have an assistant that cooks with the ingredients and equipment you actually have. Meal planning is next.

## Features

- **Write quantities the way you'd say them.** One box takes exact amounts (`500g`, `1 1/2 cups`, `1½ cups`), containers (`half a jar`, `3 cans`, `two thirds of a bag`) or a rough level (`plenty`, `about half`, `running low`, `out`). The form shows what it understood as you type, and stores the result as structured data.
- **Core vs. one-off ingredients.** Mark the staples you always keep on hand. When a core ingredient is used up it stays in your inventory as *out* and goes on the restock list. A one-off bought for a single recipe just disappears.
- **Restock list, with restock points.** Core ingredients that are low, out, or not stocked at all, with a one-click "Restocked" that records what you bought. Give an ingredient its own restock point (`¼ jar`, `2 cans`, `1 lb`, or `out`) and it goes on the list when what you have adds up to that, converting between units (`12 oz` counts against `1 lb`). Say how much you like to stock up to (`10 lb`, `4 cans`) and the list works out how much to buy: 10 lb less the 2 lb in the pantry is 8 lb, filled in when it goes on the shopping list.
- **Shopping list.** Grouped by aisle, with what you already have at home under each item. Bring in everything on the restock list in one click, add your own items, tick things off in the store, then put away what you got: check how much and where, and it's added to the inventory and comes off the list.
- **Order online.** Give a store the address of its online shop (search it for milk and paste the address) and everything on the shopping list gets a link that looks it up there. Find it, add it to the store's cart, tick it off, and put it away when the order arrives. It works with any shop that has a search box, the local grocer's included.
- **Purchase history and prices.** Every shop is recorded with its store, date, total and what each thing cost: put away what you ticked off, have Claude read the receipt, or add one by hand. See what you spent this month, which store you shop at most, and what you last paid per unit (`$3.49/lb`, `$0.33 each`) right on the shopping list.
- **Use soon.** Anything with a use-by date in the next week.
- **Organized by place.** Pantry, fridge, freezer, spice rack and counter, with filters for core items and anything running low.
- **Equipment.** Everything you cook with, from the stand mixer down to a ladle, with its brand, size and photos of your own pieces. A recipe that calls for a piece counts it as there once it's listed.
- **Recipes.** Type one in with its ingredients, equipment and steps, and keep a photo of the original card with it. Each ingredient links to the one you keep by name, and anything new is added to your ingredients.
- **What's in the kitchen for each recipe.** Every ingredient shows *on hand*, *running low* or *out*, and where it is (`2 lb · Fridge`); every piece of equipment, whether you have it. Put what's missing on the shopping list in one click, with the amounts the recipe calls for. Water and ice count as always on hand.
- **What to cook.** Recipes sorted by how close you are to making them: *ready to cook*, *a quick shop* (up to three things), *a bigger shop*, *plan ahead* or *needs equipment*, with what's missing. Mark an ingredient as hard to find, seasonal (with its months) or homemade with how long a batch takes, and recipes that need it are planned for rather than ruled out: "Plan ahead: Pickled red onions (make ahead, about 3 days)". If a variant gets you closer (no cilantro), it says so. Avocados bought hard can be marked as ripening until a date, so guacamole waits for them instead of sending you to the store. Filter by cuisine, course, main protein, diet or your own tags: something Italian, a side, a vegetarian main. The dashboard suggests what's ready or close, made longest ago first, and puts what to buy on the shopping list in one click.
- **Scale a recipe.** Halve or double it, and the amounts Mitchen can read are scaled (`1½ cups` becomes `¾ cup`); `a pinch` stays a pinch.
- **Recipes that get better.** Press *Made it* to log a batch: how it turned out and what to change next time, even days later (pickles taste right on day three). The notes wait on the recipe until you work them in, by hand or by asking Claude ("more salt, less vinegar" becomes `1½ tsp` salt and `¾ cup` vinegar, shown to you before it's saved). Every change keeps the old version, with what changed.
- **Substitutes and variants.** A substitute stands in for one ingredient when you're out (no buttermilk: milk and lemon juice), so the recipe still counts as ready to cook. A variant is a way of making it you pick up front (mild or hot salsa, blueberry pancakes), and the ingredients and shopping list follow it.
- **Claude is the assistant.** Mitchen is an MCP server, so you can connect it to the Claude app and talk to your kitchen from the counter: what can I make tonight, what's on the shopping list, what should I use up? Tell it what you bought or finished, or send it a photo of the receipt or the fridge, and it reads the changes back, saving them once you agree. Hand it a recipe from a website or a photo of a card and it saves it to your collection; tell it how dinner turned out and it goes in the cook log, then have it work those notes into the next version of the recipe.

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

Mitchen makes no AI calls itself, so there's no API key to set. To talk to your kitchen, connect Claude.

## Connecting Claude

Mitchen serves an MCP server at `/mcp`. Its tools read the kitchen (what's on hand, the restock and use-soon lists, the shopping list, recipes by how close you are to making them, equipment) and update it (bought, used up, how much is left, the shopping list, how much of a staple to keep, new recipes, the cook log, revisions from your notes, previewed before they're saved, and equipment you forgot to list, so recipes that need it count it as there), using the same actions as the app. Claude does the understanding on its side: Mitchen has no API key and makes no AI calls of its own.

The shopping list comes with a link into each store's online shop for everything on it, so a Claude that can use your browser can fill the cart there and read it back to you. Checking out stays with you.

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

Units cover weights, volumes and the containers food comes in (jar, can, bottle, bag, box, bunch, head, clove, stick…). Anything unrecognized is rejected with a hint instead of being saved as junk, so recipes and an assistant can rely on the data. The parser is `app/Support/QuantityParser.php`, and its tests in `tests/Unit` double as a list of what it understands.

## Roadmap

- [x] Ingredient catalog with core / one-off ingredients
- [x] Inventory with flexible quantities, locations and use-by dates
- [x] Restock and use-soon lists
- [x] Recipes linked to ingredients and equipment, showing what's on hand
- [x] Recipe history (a cook log and versions), substitutes and variants
- [x] Recipe suggestions: what you can make now, with a little shopping, or with time to prep ahead
- [x] Recipes sorted by cuisine, course, main protein, diet and your own tags
- [x] Equipment, with photos of your own pieces
- [x] Restock points and a shopping list built from the restock list
- [x] Stock targets: how much of a staple to keep, so the restock list says how much to buy
- [ ] Meal planning, adding to the shopping list from the plan
- [x] Stores and purchase history with prices
- [x] Links from the shopping list into a store's online shop, to order for delivery or pickup
- [ ] Filling a store's cart from the shopping list, and a nudge to order before the kitchen runs out
- [ ] Price comparison across stores, and choosing a store per shopping list item
- [x] An MCP server, so Claude can read and update the kitchen: changes in plain language ("bought 2 lbs chicken, out of milk, need eggs") or from a receipt, read back before saving, and recipes from the web
- [ ] More for Claude to do: meal ideas from what's on hand

## Development

```bash
composer dev                    # server, queue, logs and Vite together
php artisan test --compact      # Pest
vendor/bin/pint                 # code style
vendor/bin/phpstan analyse      # Larastan, level 6
```

Built with Laravel 13, Filament 5, Livewire 4, Laravel MCP and Pest.
