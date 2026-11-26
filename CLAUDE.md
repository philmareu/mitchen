# Mitchen

A kitchen assistant: ingredients, what's on hand, equipment, recipes, and (to come) meal planning. It makes no AI calls of its own: the assistant is the cook's own Claude, working with the kitchen over the MCP server. It's open source under the AGPL-3.0 and self-hosted, one kitchen per install, and grows feature by feature; the roadmap is in `README.md`.

## Stack

- Laravel 13 on PHP 8.5 (the locked packages install on 8.4.1 and up; `composer.json` says `^8.4`), Filament 5 panel served from `/` (panel id `admin`), Livewire 4, laravel/mcp 1.x with Passport 13, Pest 5, Pint, Larastan level 6.
- Verify Filament APIs against `vendor/filament/*/docs` and laravel/mcp against `vendor/laravel/mcp` and `.claude/skills/mcp-development` rather than memory.
- One kitchen per install: no tenancy, every user sees the same data (`User::canAccessPanel()` returns true).

## Domain

- `Ingredient` is the catalog entry recipes point at. `is_core` = a staple you always keep; non-core ingredients are one-offs for a single recipe. `always_on_hand` = never tracked and never out (tap water, ice): recipes count it as there. A `creating` hook turns it on for water and ice unless it was set.
- Ingredient names match ignoring case and whether the last word is singular or plural (`App\Support\Names`, through `IngredientBuilder::named()` and `ShoppingListItemBuilder::named()`), so a recipe's "Lime" links to the catalog's "Limes"; the same spelling wins when both exist. It's matched in PHP over the whole catalog. The inflector misses a few ("chili" / "chilies"). What it misses (misspellings, other names: "scallions" for "Green onions", "Blender" for the "Vitamix") is never linked silently. In the forms a new name gets a note under it ("New: added to your ingredients", "Not in your equipment"). Over MCP, `Concerns\ChecksNewNames` stops a save when a name is `Names::close()` (a typo or two, or the same last word) to one the kitchen has, until the cook confirms it: `add-recipe`, `update-recipe` and `update-kitchen` (not `need`) for ingredients (`new_ingredients`); `add-recipe` and `update-recipe` for equipment the kitchen hasn't got (`equipment_not_in_kitchen`; a revision isn't asked about equipment the recipe already has); `add-equipment` for new pieces close to a listed one or to what the recipes call for (`new_equipment`).
- Lead time: `Ingredient::sourcing` (`Sourcing`: any grocery store (default), hard to find, seasonal, make ahead), `lead_days` (hard to find and make ahead only) and `season` (months 1-12, seasonal only; its accessor stores them as sorted ints whatever the form sends). A `saving` hook clears whichever doesn't apply. `needsLeadTime()` = hard to find or make ahead, or seasonal and out of season today; `leadTime()` says what it takes ("make ahead, about 3 days", "in season Jun–Aug").
- `InventoryItem` = some of an ingredient in one `StorageLocation`. An ingredient can have several (fridge + freezer).
- Ripening: `InventoryItem::ready_on` = there but not ready until that day (hard avocados). `isRipening()` / `ripeningUntil()` only count a date after today; `ripenUntil($date)` stores it, and today or earlier clears it (= ripe now). `RestockIngredient` only adds to an item ripening until the same day, so new hard ones don't join ripe ones. A ripening item still counts as supply for the restock list. For recipes it's `Availability::Ripening` (`isMissing()` = out or ripening): missing, but `RecipeShortfall` puts it in plan ahead ("Avocados (ripening, ready Nov 19)", `leadDays()` to the date) and never in `toBuy()`/`ingredientsToBuy()`. Set it in the inventory form ("Ready on"), the "Ripe now" row action, Put away and MCP `update-kitchen` (`ProposedChange::$readyOn`, bought and set only; null leaves it alone). Ferments are left to the Labs idea.
- A quantity is stored in three columns and is **either** `amount` (+ optional `unit`) **or** `stock_level` (full/half/low/out), never both. Always read and write it through the `quantity` attribute (`App\Support\Quantity`), which also accepts typed text: `$item->quantity = 'half a jar'`. An amount of zero becomes `StockLevel::Out`.
- `App\Support\QuantityParser` reads free text ("2 lb", "1½ cups", "a couple cans", "half a dozen", "almost out"). Unknown units throw `UnreadableQuantity` rather than being stored; add spellings in `Unit::aliases()` and level phrases in `StockLevel::phrases()`. `Quantity::format()` round-trips through the parser.
- `Unit::convert()` converts within weights and within (US) volumes; containers and plain counts only match themselves. `Quantity::plus()` adds in the first quantity's unit ("1 lb" + "8 oz" = "1½ lb"), `Quantity::amountIn()` converts.
- Restock point: a core ingredient's `restock_point` (three `restock_*` columns, read and written like `quantity`) is when it goes on the restock list. `Quantity::isReachedBy()` decides: a level point is reached when no item is above that level (measured items count as above it); an amount point when items in a convertible unit add up to no more than it and nothing else usable is on hand. No point = the default "low or out". `IngredientBuilder::needsRestock()` evaluates this in PHP and returns a `whereKey()` scope, because cross-unit sums aren't portable SQL.
- Stock target: a core ingredient's `stock_target` (`target_amount` and `target_unit`, read and written like `quantity`) is how much to stock up to. It's always an amount: "none", "out" or zero is no target, and any other level throws. `Ingredient::restockAmount()` is how much to buy to be back at it (null for a one-off or with no target): `Quantity::missingFrom()` takes off what's on hand in a convertible unit, leaves out levels and other units ("low", "1 bag": no telling how much), and rounds containers and plain counts up to whole ones (`Unit::isMeasure()` = a weight or volume). `AddToShoppingList::handle()` fills it in whenever no quantity is given or on the entry already: the restock list, the dashboard's row action, a "need" with no amount, MCP `add-to-shopping-list`. The dashboard's restock list shows it ("Buy 8 lb") and MCP `restock-list` returns it as `to_buy`. `Ingredient::restockPointProblem()` and `stockTargetProblem()` (not a level; more than an amount it's restocked at) say why a typed one can't be used, for the ingredient form's rules and `update-ingredient`.
- `UseUpInventoryItem`: core → marked out (stays, lands on the restock list); one-off → deleted. Used by the inventory page's "Used up" row action and its "Mark as out" bulk action (`InventoryItemResource::markOutBulkAction()`, in the bulk menu with Delete). `RestockIngredient` replaces a low/out item in the same location, else adds to one in a compatible unit (`Quantity::plus()`), else creates one; it clears `out` placeholders elsewhere.
- `ShoppingListItem`: name, free-text quantity, category (the aisle), `bought_at` (ticked off = in the cart). A `saving` hook links it to the catalog ingredient of the same name whenever the name changes, taking the catalog spelling and category; anything else (paper towels) stays free text. `AddToShoppingList` keeps one unbought entry per name and `restockList()` brings in restock-list ingredients not on the list yet (`notOnShoppingList()`: ticked entries count as on the list). Adding is always explicit (button, row action, a "need" in `update-kitchen`); nothing is added automatically.
- An entry leaves the list (is deleted) once it's in the inventory, so nothing is counted twice: `PutAwayShopping` ("Put away what you got", `Filament/Actions/PutAwayAction`) proposes a bought change per ticked entry (location from the low/out item, else where it's kept, else `IngredientCategory::usualLocation()`; unlinked entries in the "Other" aisle get none and are just cleared), runs the confirmed ones through `ApplyKitchenUpdate`, then deletes every ticked entry it showed. "Restocked" on the dashboard deletes the ingredient's entries. `ApplyKitchenUpdate` deletes the to-buy entries it's given as bought plus ticked entries whose ingredient the update bought (ticked in the store, then reported as bought over MCP).
- Ordering online: `Store::search_url` is the address of a search for "milk" in the store's online shop, pasted from the browser (`Store::SEARCH_SAMPLE`; the store form rejects anything else). `Store::searchUrl($item)` swaps the last "milk" in it for the item, URL-encoded, and is null unless the address is http(s) with the sample in it (`shopsOnline()`); `StoreBuilder::online()` is the stores that have an address. The shopping list table has a "Find at …" row action per online store (`ShoppingListItemResource::findOnlineActions()`, named `findAt{id}`, one menu when there are several, hidden once ticked off) and `get-shopping-list` returns the same links as `find_online`. No store's API is called: ticking off is "in the cart" online too, and Put away takes it from there when the order arrives.

- Purchase history: `Store` (name, unique; matched case-insensitively with `StoreBuilder::named()`), `Purchase` (store nullable, `purchased_on`, `total`), `PurchaseItem` (name linked to the catalog by a `saving` hook like shopping list entries, `as_written` = receipt line or note words, a quantity in the same three columns as inventory, `price` = what the whole line cost). `RecordPurchase` turns the bought changes of an update into a purchase, adding an unknown store; `Purchase::addUpMissingTotal()` fills a missing total from the known prices. `ApplyKitchenUpdate` records one whenever an update buys something (`update-kitchen` and Put away; dashboard "Restocked" doesn't). `PurchaseItem::formattedPrice()` gives the unit price ("$3.49/lb", "$0.33 each"); `Ingredient::lastPaid()` (latest priced line) shows on the shopping list and ingredients table. Money is USD, Filament's and `Number`'s default.
- `Equipment`: a piece of kitchen gear (name, `EquipmentCategory`, brand and model, free-text size, `count`, `photos`, notes). Photos are kept, privately, on the default disk under `Equipment::PHOTO_DIRECTORY`, and each piece owns its files: the `OwnsPhotos` trait (shared with `Recipe`) deletes files after commit when the piece is deleted or a photo is taken off it. The form's `FileUpload` uses `preventFilePathTampering()`. When a piece is added or renamed, a `saved` hook links unlinked recipe equipment of that name.
- `Recipe`: name, source, `servings` (serves or makes, for scaling), `steps` (JSON list), notes, `photos` (the original card, kept privately under `Recipe::PHOTO_DIRECTORY`). `RecipeIngredient` lines hold a free-text `quantity` as the recipe says it ("2 cups", "a pinch", "to taste"), a note, `is_optional` and `sort`; a `saving` hook links each to the catalog ingredient of that name (taking its spelling) and **adds a one-off ingredient when there's none**, so buying it or putting it on the shopping list later finds the same ingredient. `RecipeEquipment` lines link to `Equipment` by name the same way but stay unlinked (= "Don't have") when the kitchen hasn't got it. Both repeaters save through Filament's relationship repeaters with `orderColumn('sort')`. `Recipe::ingredients()` is only the recipe's own lines (no `substitute_id` or `variant_id`) and is `chaperone()`d, so a line's `recipe` is set without a query.
- Substitutes (a fallback for one ingredient you're out of; same dish): `RecipeSubstitute` (`instead_of`, linked by name to the catalog `ingredient_id` it stands in for, note, sort) whose lines are `RecipeIngredient`s with `substitute_id` (a `saving` hook gives them the substitute's `recipe_id`). `RecipeIngredient::substitutes()` finds a line's by ingredient; `isCoveredBySubstitute()` = out, but a substitute isn't (`RecipeSubstitute::availability()` is the worst of its non-optional lines).
- Variants (a way of making it picked before cooking: hot salsa, blueberry pancakes): `RecipeVariant` (name, notes, sort) whose lines are `RecipeIngredient`s with `variant_id`. `Recipe::linesFor($variant)`: a variant line for an ingredient the recipe has replaces that line (or drops it when `is_left_out`), others are added at the end. Forms: card repeaters with a nested table repeater of lines (`RecipeResource::substitutesRepeater()` / `variantsRepeater()`, nested `->relationship()`).
- History: `CookLog` (one time it was made: `cooked_on`, `version`, `variant` name as text, `thoughts`, `next_time`, `used_in_version`), added by "Made it" on the recipe page and edited in its Cook log relation manager. `Recipe::version` is the current version; `Recipe::snapshot()` is what's versioned (servings, lines, substitutes, variants, equipment, steps; not name, source, notes, photos). `RecordRecipeVersion` compares a before-snapshot with the saved recipe and, when `RecipeChanges::between()` finds changes ("Kosher salt: 1 tsp → 1½ tsp"), adds a `RecipeVersion` (`number` = the new version, `previous` = the snapshot before, `changes`, `note`), bumps `version` and marks the cook-log notes it took in. `EditRecipe` snapshots in `beforeSave` (relationship repeaters save after it) and records in `afterSave` unless "Keep this version in the history" is off. Version 1 has no row. Old snapshots lack newer keys (`substitutes`, `variants`). Next-time notes not yet in a version (`Recipe::waitingNotes()`) are counted on the recipe page's Edit badge and marked as used by the edit form's "Notes for next time this takes in" or `update-recipe`'s `cook_log_ids`.
- What's on hand for a recipe: `RecipeIngredient::availability()` (`Availability` on hand / low / ripening / out: always on hand, or any ready item not low or out, counts as on hand; only low ready items is low; only ripening supply is ripening; `readyOn()` is the earliest date) and `whereItIs()`. Eager load `Recipe::KITCHEN` to work it out. `Recipe::shortfall($variant)` is a `RecipeShortfall`: non-optional ingredients that are out with no substitute on hand (low counts as there), plus equipment the kitchen doesn't have; `Recipe::missing()` is its names, as written. `Recipe::ingredientsToBuy($variant)` = out or low and not on the shopping list; `AddToShoppingList::fromRecipe()` adds chosen lines with the amount when it's one `Quantity::parse()` reads as measured (scaled), none for "to taste". Out lines are pre-ticked unless a substitute covers them.
- Suggestions: `RecipeShortfall::readiness()` is a `Readiness` tier: needs equipment (any missing), else ready (nothing missing), else plan ahead (any missing ingredient `needsLeadTime()`), else a quick shop (up to `RecipeShortfall::QUICK_SHOP`, 3) or a bigger shop. `Recipe::closestShortfall()` is the recipe as written unless a variant is in a closer tier ("Ready to cook as No cilantro"). `RecipeBuilder::readinessByKey()` works out every recipe's tier in PHP; `whereReadiness()` and `readyToCook()` return `whereKey()` scopes. Shown in the Recipes list tabs (`ListRecipes::getTabs()`), the "What it takes" column, the recipe page subheading (the variant shown, plus a closer variant), and the dashboard's `WhatToCook` widget (ready, quick shop and plan ahead, closest first then longest since made; its row action adds `RecipeShortfall::toBuy()`, the missing lines that aren't made ahead or on the list already).
- Describing a recipe (not versioned, like name, source and notes): `cuisine` (free text; a `saving` hook takes the spelling of one already in use, ignoring case; `RecipeBuilder::cuisines()`), `course` (`Course`), `protein` (`Protein`, null = none), `diets` (JSON list of `Diet` values, kept in enum order; `dietList()`) and `tags` (JSON list of free text, spelled like tags in use; `tagsInUse()`). Scopes `whereCuisine()`, `whereCourse()`, `whereProtein()`, `whereDiet()` (all of them) and `whereTag()` back the list filters and `what-to-cook`. `RecipeDraft::attributes()` is what `AddRecipe` and `UpdateRecipe` save on the recipe; `UpdateRecipe::preview()` lists changes to these too.
- The recipe page (`ViewRecipe`) has `$variant` (its Variant action, shown when there are variants) and `$scale`; the infolist's ingredient lines are `linesFor()` the variant.
- Scaling: `ViewRecipe::$scale` (set by its Scale action, ¼× to 3×) feeds `RecipeIngredient::quantityFor()`, which scales measured amounts with `Quantity::times()` and leaves anything unreadable as written; at 1× the text is shown exactly as written.
- `DatabaseSeeder` keeps model events on (Laravel's default `WithoutModelEvents` is removed) so the name-linking hooks run when seeding.

## Saving kitchen updates and recipes

- No AI SDK and no API key: nothing in the app prompts a model. What an assistant works out with the cook arrives over MCP and is saved by the same actions the forms use.
- `ApplyKitchenUpdate` takes `ProposedChange`s (`ChangeKind` bought / set / used_up / need), the shopping list entries they bought and `PurchaseDetails` (store, date, total), and runs in one transaction: need → `AddToShoppingList` (no ingredient is created); unknown ingredients are otherwise created as one-offs; bought → `RestockIngredient`; set → the named item (moved if the change says somewhere else), else the one in the same place, else a new one there, never an item somewhere else (what's in the fridge says nothing about the pantry jar); used up (or set to "out") → `UseUpInventoryItem`; then bought changes go to `RecordPurchase`, and the list entries given as bought, and ticked ones for anything bought, are deleted. Quantities go through `Quantity::parse()`, so an unreadable one fails the whole save. Its callers are MCP `update-kitchen` (through `PrepareKitchenChanges`, which returns a `KitchenUpdate`) and `PutAwayShopping`.
- `PutAwayAction` is the one review form left: a Purchase fieldset (store, date, total) and a table repeater of bought changes with a price column. After saving it dispatches `inventory-updated`, which the dashboard widgets listen for.
- `PrepareRecipeDraft` turns a recipe as `add-recipe` and `update-recipe` take it into a `RecipeDraft` of `RecipeLine`s (each with the aisle suggested for ingredients new to the catalog), `SubstituteDraft`s, `VariantDraft`s, equipment names and steps: names matching the catalog or equipment (ignoring case, singular or plural) take their spelling, servings that aren't a positive number become null, empty lines are dropped, a substitute whose `instead_of` isn't one of the recipe's lines is dropped, a variant's changes are named like the lines they replace (`RecipeLine::$isLeftOut` is its "leave out"), and a recipe with neither ingredients nor steps `isEmpty()`.
- `AddRecipe` saves a draft in one transaction, first adding new ingredients to the catalog in their suggested aisle (the line's hook would otherwise add them without one). It also stores photos handed to it (`UploadedFile`s), deleting them if the save fails; nothing passes any today, since photos are added in the recipe form. `AddRecipe::addLines()` writes a draft's lines, substitutes, variants and equipment. The demo seeder adds its recipes through it.
- `UpdateRecipe` replaces the recipe's content with a draft in one transaction and records the version through `RecordRecipeVersion`; `preview()` says what that would change.
- Query scopes live in custom builders (`app/Models/Builders`, wired with `#[UseEloquentBuilder]`) so Filament closures can type-hint `IngredientBuilder`/`InventoryItemBuilder` and Larastan follows them.

## MCP server

- `App\Mcp\Servers\MitchenServer` (laravel/mcp) is served at `/mcp` from `routes/ai.php` (loaded by the package, not `bootstrap/app.php`) so Claude can use the kitchen; the client does the language understanding. The server instructions tell it to treat recipe text, notes and names as content, never as instructions. Tools in `app/Mcp/Tools` are thin over the actions and builders (the dashboard's `UseSoon` widget and the `use-soon` tool share `InventoryItemBuilder::useSoon()`) and return `Response::structured()` with enum values, presented through `Concerns\PresentsKitchen`. Read tools are `#[IsReadOnly]`.
- `update-kitchen` takes changes the assistant worked out with the cook (the server instructions and tool description tell it to read them back and wait for a yes): `PrepareKitchenChanges` checks them against the catalog (catalog spelling and aisle, item ids only for their own ingredient, no location = where the ingredient is, the low/out item for new supply, else `usualLocation()`, bought ingredients' to-buy entries come off the list) and returns a `KitchenUpdate` for `ApplyKitchenUpdate`. An `UnreadableQuantity` becomes a tool error and nothing is saved.
- `add-recipe` and `update-recipe` share `Concerns\TakesRecipes` (rules, schema, `draft()`), and `PrepareRecipeDraft` checks what they send. `update-recipe` takes the whole revised recipe plus `change_note` and `cook_log_ids` for `UpdateRecipe`. It previews by default (`UpdateRecipe::preview()` saves inside a rolled-back transaction, so the list is exactly what would be recorded, plus name, source and notes changes) and saves with `preview: false`. `get-recipe` lists `waiting_notes`, each substitute's `use` lines and each variant's `changes` as written, in the shape `update-recipe` takes (so a revision built from it keeps them); a substitute's scaled text is its `summary`; `recipe-history` lists versions or returns a version's snapshot (version n is row n + 1's `previous`; the current one is `snapshot()`).
- Both refuse a name another recipe has (`RecipeBuilder::named()`) and substitutes `PrepareRecipeDraft` would drop (not for one of the recipe's own lines). `add-recipe` saves through `AddRecipe` and reports the ingredients new to the catalog, missing equipment and readiness. `log-cook` is "Made it" (variant names must be the recipe's), or adds to an earlier entry by `cook_log_id` (from `get-recipe`), changing only the fields given.
- `update-ingredient` changes how a catalog ingredient is kept stocked, by name: `core`, `restock_point` and `stock_target` ("none" takes the target off), only what's passed. A restock point or target for a one-off is refused unless `core: true` comes with it, and nothing is saved when either can't be used. `presentIngredient()` returns `stock_target` for `list-inventory` and `restock-list`.
- `list-equipment` also returns `not_in_kitchen` (`missingFromKitchen()`: what the recipes call for, with the recipes). `add-equipment` adds pieces by name (taking the spelling of what the recipes call for when it's the same singular or plural, so the saved hook links those recipes) or, for one already listed by that name (singular or plural too), changes only the details given.
- Sign-in is OAuth 2.1 through Laravel Passport: `Mcp::oauthRoutes()` (discovery and open dynamic client registration, throttled) and `auth:api` on `/mcp`. Anyone can register a client under any name, so `config/mcp.php` limits where one may redirect to: `https://claude.ai` (the Claude apps' callback) and loopback addresses on any port (Claude Code), or what `MCP_REDIRECT_DOMAINS` lists. The consent page leads with the redirect host for the same reason. Access tokens last a day and refresh tokens 60 days, rotated on each use (`AppServiceProvider`); Passport's device grant is off. Passport's consent page is `resources/views/mcp/authorize.blade.php` (plain inline CSS, no Vite), set in `AppServiceProvider`; guests are sent to Filament's login by `redirectGuestsTo()` in `bootstrap/app.php`, and Filament's login returns them to the intended URL. Keys come from `php artisan passport:keys` (`storage/oauth-*.key`, gitignored) or `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` (`config/passport.php`).
- `lcobucci/jwt` (via Passport) requires `ext-sodium` for Composer, though Passport's RS256 tokens don't use it at runtime. Without it, `composer install` fails its platform check.

## Rules

- `.env.example` defaults to a SQLite file and tests use in-memory SQLite, but Mitchen has to work on MySQL too, so stay driver-neutral. Check `.env` for the database this checkout uses before running anything that writes to it.
- Business logic goes in `app/Actions` (and pure helpers in `app/Support`), not in Filament classes.
- Serve with `php artisan serve` and no `--port`; read the URL from its output. Stop servers you started. To click around without touching the dev data, migrate and seed a scratch SQLite file and serve with `DB_CONNECTION=sqlite DB_DATABASE=<file> php artisan serve --no-reload` (without `--no-reload` the server drops env vars so `.env` wins).
- No custom Filament theme: Tailwind classes in Filament schemas aren't compiled.
- Filament notifications render their title and body as HTML (sanitized, so links and images get through). Wrap any name or other stored text in `e()` before it goes into one. Names, notes and recipe text can come from a web page by way of an assistant: never render them with `->html()`, `HtmlString` or `{!! !!}` unescaped.
- Photos are kept on the default disk: `local` (`storage/app/private`) unless `FILESYSTEM_DISK=s3` (`league/flysystem-aws-s3-v3`). Livewire's temporary uploads always stay on `local` (`AppServiceProvider`), so the browser never uploads straight to the bucket and it only needs a `GET` CORS rule. Image columns and entries turn off `checkFileExistence()`, which would cost an S3 request per photo. The `s3` disk has `throw` on: Livewire's `storeAs()` returns the path even when the write fails, so a failed upload would otherwise be saved as a photo that isn't there.

## Installing and hosting

- The README's "Try it with sample data", "Install" and "Run it on a server" steps are the supported ways in, and each was run in a clean copy. When setup changes, change the README with it and run the steps again.
- `composer setup` installs dependencies and creates `.env`, an app key and `database/database.sqlite`. It doesn't migrate: `php artisan migrate --seed` (the demo) or `php artisan mitchen:install` (a real install) comes next. There is no front-end build: no view uses Vite.
- `mitchen:install` (`App\Console\Commands\MitchenInstallCommand`) migrates, refuses while the demo login exists, runs `passport:keys` when there are no keys in the environment or in `storage/`, then creates the login through `make:filament-user`. `--name`, `--email` and `--password` skip the prompts and are validated the same way.
- `.env.example` stays like Laravel's own: `APP_ENV=local`, `APP_DEBUG=true`, SQLite, no explanatory comments. What a server needs (`APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE`, `TRUSTED_PROXIES`) is explained in the README.
- `config/trustedproxy.php` reads `TRUSTED_PROXIES`, which Laravel's `TrustProxies` middleware falls back to. `config/session.php` makes the session cookie HTTPS-only when `APP_URL` starts with `https://`. `config/app.php` takes the timezone from `APP_TIMEZONE` (UTC without it). `public/robots.txt` disallows everything.
- There is no registration, password reset, profile page or page to revoke an MCP client: logins are made on the server, and the README's Security section has the commands.
- CI (`.github/workflows/tests.yml`) runs Pest on PHP 8.4 and 8.5, Pint and Larastan on every push to `main` and every pull request, read-only and with no secrets. Actions are pinned to commits; Dependabot opens one grouped pull request a month for Composer and one for the actions.
- License: AGPL-3.0-only (`LICENSE`, `composer.json`). `CONTRIBUTING.md` sets the terms a pull request is accepted on; `SECURITY.md` says how to report a vulnerability.

## Tests

- `php artisan test --compact`; `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse` before committing.
- Filament tests use `livewire(...)` with `TestAction::make('name')->table($record)` for row actions; `actingAsCook()`, `sentRecipe()` (a recipe as an assistant sends it to `add-recipe`) and `fakeReceiptPhoto()` are in `tests/Pest.php`. Relation managers are tested with `livewire(XRelationManager::class, ['ownerRecord' => $recipe, 'pageClass' => ViewRecipe::class])`. After a Livewire call, `assertSee*` reads the JSON response, where non-ASCII text such as ñ is escaped: assert an ASCII part of it. Equipment tests use `Storage::fake()`. The ingredient factory has `hardToFind()`, `makeAhead()` and `seasonal([months])` states; use `$this->travelTo()` for seasons. Switch list tabs with `->set('activeTab', 'quick_shop')`; tab badges are strings.
- `fakeReceiptPhoto()` is a real 1×1 JPEG, for any photo a test uploads (there's no GD for `UploadedFile::fake()->image()`). Action modal content isn't in Livewire test HTML: assert schema state, not `assertSee`. To edit repeater rows in a test, pass `fillForm()` a closure: a dotted key such as `changes.0.quantity` replaces the whole `changes` array. Once photos are uploaded, fill other fields with a plain array instead (a closure would re-fill the uploaded files and fail). A simple repeater's raw state holds each item under its field name: fill steps as `[['step' => 'Mix.'], ...]` (and `$set()` them that way); dehydrated `$data` is a flat list. The inventory item factory picks a random location, so set it when a test reads it back. Tick a table checkbox column with `->call('updateTableColumnState', 'bought', (string) $id, true)`.
- MCP tools are tested with `MitchenServer::actingAs($user)->tool(Tool::class, [...])` and `assertStructuredContent(fn (AssertableJson $json) => ...)` or `assertHasErrors([...])`; HTTP tests of `/mcp` use `Passport::actingAs()`. Passport's token guard needs keys even to refuse a request, so `tests/Feature/Mcp/MitchenServerTest.php` generates a key pair into `passport.private_key`/`public_key` in `beforeEach()`; it also runs the whole OAuth handshake (register, approve, token, tool call).
- `php artisan migrate:fresh --seed` loads `DemoKitchenSeeder` (login `test@example.com` / `password`, its `EMAIL` and `PASSWORD` constants). The password is public, so the seeder refuses to run in production, in a database with any other login, and in a kitchen that already has something in it (`DemoKitchenSeederTest`).

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
