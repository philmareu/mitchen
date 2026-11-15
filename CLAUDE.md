# Mitchen

A kitchen assistant: ingredients, what's on hand, equipment, recipes, and (to come) meal planning and more AI help on top. Built as an MVP to grow feature by feature and kept presentable as a possible open-source project; the roadmap is in `README.md`.

## Stack

- Laravel 13 on PHP 8.5, Filament 5 panel served from `/` (panel id `admin`), Livewire 4, laravel/ai 1.x with Anthropic, laravel/mcp 1.x with Passport 13, Pest 5, Pint, Larastan level 6.
- Verify Filament APIs against `vendor/filament/*/docs` and the AI SDK against `vendor/laravel/ai` and `.claude/skills/ai-sdk-development` rather than memory.
- One kitchen per install: no tenancy, every user sees the same data (`User::canAccessPanel()` returns true).

## Domain

- `Ingredient` is the catalog entry recipes point at. `is_core` = a staple you always keep; non-core ingredients are one-offs for a single recipe. `always_on_hand` = never tracked and never out (tap water, ice): recipes count it as there. A `creating` hook turns it on for water and ice unless it was set.
- Ingredient names match ignoring case and whether the last word is singular or plural (`App\Support\Names`, through `IngredientBuilder::named()` and `ShoppingListItemBuilder::named()`), so a recipe's "Lime" links to the catalog's "Limes"; the same spelling wins when both exist. It's matched in PHP over the whole catalog. The inflector misses a few ("chili" / "chilies").
- Lead time: `Ingredient::sourcing` (`Sourcing`: any grocery store (default), hard to find, seasonal, make ahead), `lead_days` (hard to find and make ahead only) and `season` (months 1-12, seasonal only; its accessor stores them as sorted ints whatever the form sends). A `saving` hook clears whichever doesn't apply. `needsLeadTime()` = hard to find or make ahead, or seasonal and out of season today; `leadTime()` says what it takes ("make ahead, about 3 days", "in season Jun–Aug").
- `InventoryItem` = some of an ingredient in one `StorageLocation`. An ingredient can have several (fridge + freezer).
- A quantity is stored in three columns and is **either** `amount` (+ optional `unit`) **or** `stock_level` (full/half/low/out), never both. Always read and write it through the `quantity` attribute (`App\Support\Quantity`), which also accepts typed text: `$item->quantity = 'half a jar'`. An amount of zero becomes `StockLevel::Out`.
- `App\Support\QuantityParser` reads free text ("2 lb", "1½ cups", "a couple cans", "half a dozen", "almost out"). Unknown units throw `UnreadableQuantity` rather than being stored; add spellings in `Unit::aliases()` and level phrases in `StockLevel::phrases()`. `Quantity::format()` round-trips through the parser.
- `Unit::convert()` converts within weights and within (US) volumes; containers and plain counts only match themselves. `Quantity::plus()` adds in the first quantity's unit ("1 lb" + "8 oz" = "1½ lb"), `Quantity::amountIn()` converts.
- Restock point: a core ingredient's `restock_point` (three `restock_*` columns, read and written like `quantity`) is when it goes on the restock list. `Quantity::isReachedBy()` decides: a level point is reached when no item is above that level (measured items count as above it); an amount point when items in a convertible unit add up to no more than it and nothing else usable is on hand. No point = the default "low or out". `IngredientBuilder::needsRestock()` evaluates this in PHP and returns a `whereKey()` scope, because cross-unit sums aren't portable SQL.
- `UseUpInventoryItem`: core → marked out (stays, lands on the restock list); one-off → deleted. `RestockIngredient` replaces a low/out item in the same location, else adds to one in a compatible unit (`Quantity::plus()`), else creates one; it clears `out` placeholders elsewhere.
- `ShoppingListItem`: name, free-text quantity, category (the aisle), `bought_at` (ticked off = in the cart). A `saving` hook links it to the catalog ingredient of the same name whenever the name changes, taking the catalog spelling and category; anything else (paper towels) stays free text. `AddToShoppingList` keeps one unbought entry per name and `restockList()` brings in restock-list ingredients not on the list yet (`notOnShoppingList()`: ticked entries count as on the list). Adding is always explicit (button, row action, "need" in Quick update); nothing is added automatically.
- An entry leaves the list (is deleted) once it's in the inventory, so nothing is counted twice: `PutAwayShopping` ("Put away what you got", `Filament/Actions/PutAwayAction`) proposes a bought change per ticked entry (location from the low/out item, else where it's kept, else `IngredientCategory::usualLocation()`; unlinked entries in the "Other" aisle get none and are just cleared), runs the confirmed ones through `ApplyKitchenUpdate`, then deletes every ticked entry it showed. "Restocked" on the dashboard deletes the ingredient's entries. `ApplyKitchenUpdate` deletes the entries the cook ticked in the review plus ticked entries whose ingredient the update bought (ticked in the store, then a receipt photo).

- Purchase history: `Store` (name, unique; matched case-insensitively with `StoreBuilder::named()`), `Purchase` (store nullable, `purchased_on`, `total`), `PurchaseItem` (name linked to the catalog by a `saving` hook like shopping list entries, `as_written` = receipt line or note words, a quantity in the same three columns as inventory, `price` = what the whole line cost). `RecordPurchase` turns the bought changes of an update into a purchase, adding an unknown store; `Purchase::addUpMissingTotal()` fills a missing total from the known prices. `ApplyKitchenUpdate` records one whenever an update buys something (Quick update and Put away; dashboard "Restocked" doesn't). `PurchaseItem::formattedPrice()` gives the unit price ("$3.49/lb", "$0.33 each"); `Ingredient::lastPaid()` (latest priced line) shows on the shopping list and ingredients table. Money is USD, Filament's and `Number`'s default.
- `Equipment`: a piece of kitchen gear (name, `EquipmentCategory`, brand and model, free-text size, `count`, `photos`, notes). Photos are kept, privately, on the default disk under `Equipment::PHOTO_DIRECTORY`, and each piece owns its files: Quick add stores a separate copy per piece (a drawer photo goes on every utensil in it, to crop later in the edit form's image editor), and the `OwnsPhotos` trait (shared with `Recipe`) deletes files after commit when the piece is deleted or a photo is taken off it. The form's `FileUpload` uses `preventFilePathTampering()`. When a piece is added or renamed, a `saved` hook links unlinked recipe equipment of that name.
- `Recipe`: name, source, `servings` (serves or makes, for scaling), `steps` (JSON list), notes, `photos` (the original card, kept privately under `Recipe::PHOTO_DIRECTORY`). `RecipeIngredient` lines hold a free-text `quantity` as the recipe says it ("2 cups", "a pinch", "to taste"), a note, `is_optional` and `sort`; a `saving` hook links each to the catalog ingredient of that name (taking its spelling) and **adds a one-off ingredient when there's none**, so buying it or putting it on the shopping list later finds the same ingredient. `RecipeEquipment` lines link to `Equipment` by name the same way but stay unlinked (= "Don't have") when the kitchen hasn't got it. Both repeaters save through Filament's relationship repeaters with `orderColumn('sort')`. `Recipe::ingredients()` is only the recipe's own lines (no `substitute_id` or `variant_id`) and is `chaperone()`d, so a line's `recipe` is set without a query.
- Substitutes (a fallback for one ingredient you're out of; same dish): `RecipeSubstitute` (`instead_of`, linked by name to the catalog `ingredient_id` it stands in for, note, sort) whose lines are `RecipeIngredient`s with `substitute_id` (a `saving` hook gives them the substitute's `recipe_id`). `RecipeIngredient::substitutes()` finds a line's by ingredient; `isCoveredBySubstitute()` = out, but a substitute isn't (`RecipeSubstitute::availability()` is the worst of its non-optional lines).
- Variants (a way of making it picked before cooking: hot salsa, blueberry pancakes): `RecipeVariant` (name, notes, sort) whose lines are `RecipeIngredient`s with `variant_id`. `Recipe::linesFor($variant)`: a variant line for an ingredient the recipe has replaces that line (or drops it when `is_left_out`), others are added at the end. Forms: card repeaters with a nested table repeater of lines (`RecipeResource::substitutesRepeater()` / `variantsRepeater()`, nested `->relationship()` in the resource form only).
- History: `CookLog` (one time it was made: `cooked_on`, `version`, `variant` name as text, `thoughts`, `next_time`, `used_in_version`), added by "Made it" on the recipe page and edited in its Cook log relation manager. `Recipe::version` is the current version; `Recipe::snapshot()` is what's versioned (servings, lines, substitutes, variants, equipment, steps; not name, source, notes, photos). `RecordRecipeVersion` compares a before-snapshot with the saved recipe and, when `RecipeChanges::between()` finds changes ("Kosher salt: 1 tsp → 1½ tsp"), adds a `RecipeVersion` (`number` = the new version, `previous` = the snapshot before, `changes`, `note`), bumps `version` and marks the cook-log notes it took in. `EditRecipe` snapshots in `beforeSave` (relationship repeaters save after it) and records in `afterSave` unless "Keep this version in the history" is off. Version 1 has no row. Old snapshots lack newer keys (`substitutes`, `variants`).
- What's on hand for a recipe: `RecipeIngredient::availability()` (`Availability` on hand / low / out: always on hand, or any item not low or out, counts as on hand; only low items is low) and `whereItIs()`. Eager load `Recipe::KITCHEN` to work it out. `Recipe::shortfall($variant)` is a `RecipeShortfall`: non-optional ingredients that are out with no substitute on hand (low counts as there), plus equipment the kitchen doesn't have; `Recipe::missing()` is its names, as written. `Recipe::ingredientsToBuy($variant)` = out or low and not on the shopping list; `AddToShoppingList::fromRecipe()` adds chosen lines with the amount when it's one `Quantity::parse()` reads as measured (scaled), none for "to taste". Out lines are pre-ticked unless a substitute covers them.
- Suggestions: `RecipeShortfall::readiness()` is a `Readiness` tier: needs equipment (any missing), else ready (nothing missing), else plan ahead (any missing ingredient `needsLeadTime()`), else a quick shop (up to `RecipeShortfall::QUICK_SHOP`, 3) or a bigger shop. `Recipe::closestShortfall()` is the recipe as written unless a variant is in a closer tier ("Ready to cook as No cilantro"). `RecipeBuilder::readinessByKey()` works out every recipe's tier in PHP; `whereReadiness()` and `readyToCook()` return `whereKey()` scopes. Shown in the Recipes list tabs (`ListRecipes::getTabs()`), the "What it takes" column, the recipe page subheading (the variant shown, plus a closer variant), and the dashboard's `WhatToCook` widget (ready, quick shop and plan ahead, closest first then longest since made; its row action adds `RecipeShortfall::toBuy()`, the missing lines that aren't made ahead or on the list already).
- Describing a recipe (not versioned, like name, source and notes): `cuisine` (free text; a `saving` hook takes the spelling of one already in use, ignoring case; `RecipeBuilder::cuisines()`), `course` (`Course`), `protein` (`Protein`, null = none), `diets` (JSON list of `Diet` values, kept in enum order; `dietList()`) and `tags` (JSON list of free text, spelled like tags in use; `tagsInUse()`). Scopes `whereCuisine()`, `whereCourse()`, `whereProtein()`, `whereDiet()` (all of them) and `whereTag()` back the list filters and `what-to-cook`. `RecipeDraft::attributes()` is what `AddRecipe` and `UpdateRecipe` save on the recipe; `UpdateRecipe::preview()` lists changes to these too. The readers return them (course and protein enums list null so a strict schema allows "none") and get the cuisines and tags in use.
- The recipe page (`ViewRecipe`) has `$variant` (its Variant action, shown when there are variants) and `$scale`; the infolist's ingredient lines are `linesFor()` the variant.
- Scaling: `ViewRecipe::$scale` (set by its Scale action, ¼× to 3×) feeds `RecipeIngredient::quantityFor()`, which scales measured amounts with `Quantity::times()` and leaves anything unreadable as written; at 1× the text is shown exactly as written.
- `DatabaseSeeder` keeps model events on (Laravel's default `WithoutModelEvents` is removed) so the name-linking hooks run when seeding.

## AI: quick update, receipts and stock-takes

- `App\Filament\Actions\QuickUpdateAction` (Dashboard, Inventory and Shopping list headers) is a two-step wizard: a plain-language note and/or up to 4 photos (resized in the browser to 2576 px, the most current Claude models read; never stored), then a review step: where, when and how much the shop was (store, date, total; shown when something is bought), a table repeater of proposed changes with a price column for bought rows, a checkbox list of shopping list entries to take off the list (pre-ticked with what was bought), and, after a stock-take, an unticked checkbox list of items the photos should have shown but didn't. Nothing is written until the cook confirms.
- `ReadKitchenUpdate` prompts `Ai/Agents/KitchenUpdateReader` (structured output, `#[UseCheapestModel]` → `ANTHROPIC_FAST_MODEL`), or `PhotoReader` when there are photos. `PhotoReader` extends it with rules for receipts (bought) and storage photos (a stock-take of "set" changes, plus `not_seen` item ids), and runs on the default model (`ANTHROPIC_MODEL`): PHP attributes aren't inherited, so it redeclares `#[MaxTokens]`/`#[Timeout]` and never picks up `#[UseCheapestModel]`. Both get the note, the whole catalog (core flag, restock point, items on hand), the unbought shopping list and the saved store names, then distrust the answer: names matched to the catalog like `named()` and case-insensitively to stores, item and list ids that don't exist or don't fit dropped, prices that aren't non-negative numbers (or are on anything but a bought change) and future purchase dates dropped, unknown enums defaulted, malformed lists treated as empty. `bought_from_list` also gains any list entry whose ingredient is bought in the update; `not_seen` drops items already out or changed in the update. Result is a `KitchenUpdate`: `ProposedChange`s (`ChangeKind` bought / set / used_up / need), not-understood text, `boughtFromList`, `notSeen` and `PurchaseDetails` (store, date, total).
- `ApplyKitchenUpdate` runs in one transaction: need → `AddToShoppingList` (no ingredient is created); unknown ingredients are otherwise created as one-offs; bought → `RestockIngredient`; set → the named item (moved if the cook changed where), else the one in the same place, else a new one there, never an item somewhere else (a fridge photo says nothing about the pantry jar); used up (or set to "out") → `UseUpInventoryItem`; then bought changes go to `RecordPurchase`, and the confirmed list entries, and ticked ones for anything bought, are deleted. Not-seen items the cook ticks become used-up changes (`ProposedChange::useUp()`). Quantities go through `Quantity::parse()`, so an unreadable one fails the whole save and the review form shows the parser's error.
- The action is disabled without a key (`KitchenUpdateReader::isConfigured()`); AI failures are reported and shown as a notification, keeping the cook on step 1. After saving it dispatches `inventory-updated`, which the dashboard widgets listen for.
- Reading runs inside the Livewire request (a photo takes 5–20 s on Sonnet, longer on Opus). Behind PHP-FPM, raise `max_execution_time` past `PhotoReader`'s 180 s timeout.

## AI: equipment

- `App\Filament\Actions\QuickAddEquipmentAction` ("Quick add" on the Equipment page) is the same two-step wizard for equipment: up to 4 photos (resized like Quick update's, but kept) and/or a note, then a review step with numbered photo previews and a table repeater of pieces (`EquipmentChangeKind` added / existing / gone) whose Photos column picks the photos each piece is in.
- `ReadEquipmentUpdate` prompts `Ai/Agents/EquipmentReader` (structured output, always the default model: equipment changes rarely and identifying it needs good eyes) with the note, the photo count, the listed equipment and what the recipes call for that isn't listed (`RecipeEquipmentBuilder::missingFromKitchen()`, so a new piece takes the recipes' name and links up), then distrusts the answer: an "existing" piece with an unknown id becomes "added", a "gone" one is dropped, listed pieces keep their name and category, photo numbers (1-based from the model, 0-based after) outside what was attached are dropped, counts that aren't positive numbers fall back. Result is an `EquipmentUpdate` of `ProposedEquipment`s and not-understood text. The review's name field suggests those recipe names too, and says which recipes a new piece is needed for.
- `ApplyEquipmentUpdate` stores the photo copies, then saves in one transaction (added → created; existing → updated and photos appended, found by id or, when the cook switched a row to "Already have", by name; gone → deleted with its photos), deleting the stored copies if anything fails.

## AI: recipes

- `App\Filament\Actions\QuickAddRecipeAction` ("Quick add" on the Recipes list) is the same two-step wizard: up to 4 photos (kept with the recipe) and/or a note (a typed or pasted recipe, or corrections to the photos), then a review step with photo previews and the recipe form (`RecipeResource::detailFields()`, `ingredientsRepeater(withAisle: true)`, `equipmentRepeater()`, `stepsRepeater()`, shared with the resource form). Saving redirects to the recipe page.
- `ReadRecipe` prompts `Ai/Agents/RecipeReader` (structured output, always the default model: handwriting) with the note, the photo count, the catalog's ingredient names and the equipment names, then tidies the answer: names matching the catalog or equipment (ignoring case, singular or plural) take their spelling, servings that aren't a positive number become null, empty lines are dropped, a recipe with neither ingredients nor steps counts as nothing found. Result is a `RecipeDraft` of `RecipeLine`s (each with the aisle the assistant suggests for ingredients new to the catalog), equipment names, steps and not-understood text.
- The reader also returns substitutes (`instead_of` must match one of its ingredient lines, else dropped) and variants (changes named like the lines they replace), as `SubstituteDraft`s and `VariantDraft`s; `RecipeLine::$isLeftOut` is a variant's "leave out".
- `AddRecipe` stores the photos, then saves in one transaction, first adding new ingredients to the catalog in their suggested aisle (the line's hook would otherwise add them without one), deleting the stored photos if anything fails. `AddRecipe::addLines()` writes a draft's lines, substitutes, variants and equipment. The demo seeder adds its recipes through it.
- "Update from notes" (`Filament/Actions/UpdateRecipeFromNotesAction` on the recipe page, badge = waiting next-time notes) is the same two-step wizard: tick cook-log notes and/or type changes, then review. `ReviseRecipe` prompts `Ai/Agents/RecipeReviser` (extends `RecipeReader`: same schema plus `summary`, default model) with the current recipe as JSON, the notes and the catalog, and tidies it with `ReadRecipe::tidy()`; `RecipeDraft::$changeNote` fills "What changed and why". `UpdateRecipe` replaces the recipe's content with the checked draft in one transaction and records the version through `RecordRecipeVersion`. Both wizards share `RecipeResource::draftFields()` and `fillDraft()`.

## AI usage

- The SDK reports tokens per response but neither stores nor prices them. `App\Listeners\RecordAiUsage` (auto-discovered, bound as a singleton in `AppServiceProvider` so it can time calls) writes an `AiCall` for every `AgentPrompted` and `AgentFailed`, timed from `PromptingAgent`. It runs inside `rescue()`: bookkeeping never breaks the feature. Calls made through any agent, fakes included, are recorded.
- `App\Support\AiPricing` prices usage from `config('ai.pricing')` (USD per million tokens, 5-minute cache-write rate, optional `long_context` tier). Unknown models get a null cost ("No price"). Update the rates when Anthropic's change; the cost is stored at call time.
- `AiCallResource` (`/ai-usage`) is read-only: stats header (`AiUsageOverview`) and a filterable log with a cost total. Image tokens are billed as ordinary input tokens, so receipts need no special handling.
- Query scopes live in custom builders (`app/Models/Builders`, wired with `#[UseEloquentBuilder]`) so Filament closures can type-hint `IngredientBuilder`/`InventoryItemBuilder` and Larastan follows them.

## MCP server

- `App\Mcp\Servers\MitchenServer` (laravel/mcp) is served at `/mcp` from `routes/ai.php` (loaded by the package, not `bootstrap/app.php`) so Claude can use the kitchen; the client does the language understanding, so no `ANTHROPIC_API_KEY` calls. Tools in `app/Mcp/Tools` are thin over the actions and builders (the dashboard's `UseSoon` widget and the `use-soon` tool share `InventoryItemBuilder::useSoon()`) and return `Response::structured()` with enum values, presented through `Concerns\PresentsKitchen`. Read tools are `#[IsReadOnly]`.
- `update-kitchen` takes changes the assistant worked out with the cook (the server instructions and tool description tell it to read them back and wait for a yes): `PrepareKitchenChanges` checks them against the catalog like `ReadKitchenUpdate` (catalog spelling and aisle, item ids only for their own ingredient, no location = where the ingredient is, the low/out item for new supply, else `usualLocation()`, bought ingredients' to-buy entries come off the list) and returns a `KitchenUpdate` for `ApplyKitchenUpdate`. An `UnreadableQuantity` becomes a tool error and nothing is saved.
- `add-recipe` and `update-recipe` share `Concerns\TakesRecipes` (rules, schema, `draft()`): they take the `RecipeReader` schema (minus `not_understood`), so `ReadRecipe::tidy()` checks it. `update-recipe` is "Update from notes": the whole revised recipe plus `change_note` and `cook_log_ids` for `UpdateRecipe`. It previews by default (`UpdateRecipe::preview()` saves inside a rolled-back transaction, so the list is exactly what would be recorded, plus name, source and notes changes) and saves with `preview: false`. `get-recipe` lists `waiting_notes` and each variant's `changes` as written, in the shape `update-recipe` takes (so a revision built from it keeps them); `recipe-history` lists versions or returns a version's snapshot (version n is row n + 1's `previous`; the current one is `snapshot()`).
- Both refuse a name another recipe has (`RecipeBuilder::named()`) and substitutes `tidy()` would drop (not for one of the recipe's own lines). `add-recipe` saves through `AddRecipe` and reports the ingredients new to the catalog, missing equipment and readiness. `log-cook` is "Made it" (variant names must be the recipe's), or adds to an earlier entry by `cook_log_id` (from `get-recipe`), changing only the fields given.
- `list-equipment` also returns `not_in_kitchen` (`missingFromKitchen()`: what the recipes call for, with the recipes). `add-equipment` adds pieces by name (the saved hook links the recipes that call for them) or, for one already listed by that name, changes only the details given.
- Sign-in is OAuth 2.1 through Laravel Passport: `Mcp::oauthRoutes()` (discovery and open dynamic client registration, throttled) and `auth:api` on `/mcp`. Passport's consent page is `resources/views/mcp/authorize.blade.php` (plain inline CSS, no Vite), set in `AppServiceProvider`; guests are sent to Filament's login by `redirectGuestsTo()` in `bootstrap/app.php`, and Filament's login returns them to the intended URL. Keys come from `php artisan passport:keys` (`storage/oauth-*.key`, gitignored) or `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` (`config/passport.php`).
- `lcobucci/jwt` (via Passport) requires `ext-sodium` for Composer, though Passport's RS256 tokens don't use it at runtime. Without it, `composer install` fails its platform check.

## Rules

- Dev database on this machine: MySQL `mitchen` at 127.0.0.1:3306 (root, no password). Tests use in-memory SQLite, so stay driver-neutral.
- `ANTHROPIC_API_KEY` lives only in `.env`. Models are set in `config/ai.php`: `ANTHROPIC_MODEL` (default `claude-opus-5-5`) and `ANTHROPIC_FAST_MODEL` (default `claude-haiku-5-5`, used by quick update).
- Business logic goes in `app/Actions` (and pure helpers in `app/Support`), not in Filament classes.
- Serve with `php artisan serve` and no `--port`; read the URL from its output. Stop servers you started. To click around without touching the dev data, migrate and seed a scratch SQLite file and serve with `DB_CONNECTION=sqlite DB_DATABASE=<file> php artisan serve --no-reload` (without `--no-reload` the server drops env vars so `.env` wins).
- No custom Filament theme: Tailwind classes in Filament schemas aren't compiled.
- Photos are kept on the default disk: `local` (`storage/app/private`) in development, `s3` in production (Laravel Forge, `league/flysystem-aws-s3-v3`). Livewire's temporary uploads always stay on `local` (`AppServiceProvider`) because the AI actions read photos from a local path (`getRealPath()`). Image columns and entries turn off `checkFileExistence()`, which would cost an S3 request per photo. The `s3` disk has `throw` on: Livewire's `storeAs()` returns the path even when the write fails, so a failed upload would otherwise be saved as a photo that isn't there.

## Tests

- `php artisan test --compact`; `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse` before committing.
- Filament tests use `livewire(...)` with `TestAction::make('name')->table($record)` for row actions; `actingAsCook()`, `readerChange()` (one raw agent change), `readerPiece()` (one raw equipment piece) and `readerRecipe()` (one raw recipe; it's also the reviser's shape, plus `summary`) are in `tests/Pest.php`. Relation managers are tested with `livewire(XRelationManager::class, ['ownerRecord' => $recipe, 'pageClass' => ViewRecipe::class])`. After a Livewire call, `assertSee*` reads the JSON response, where non-ASCII text such as ñ is escaped: assert an ASCII part of it. Equipment tests use `Storage::fake()`. The ingredient factory has `hardToFind()`, `makeAhead()` and `seasonal([months])` states; use `$this->travelTo()` for seasons. Switch list tabs with `->set('activeTab', 'quick_shop')`; tab badges are strings.
- Fake the agents with `KitchenUpdateReader::fake([[...]])` / `PhotoReader::fake(...)` / `EquipmentReader::fake(...)` / `RecipeReader::fake(...)` / `RecipeReviser::fake(...)`; quick update tests set `ai.providers.anthropic.key` so the action is enabled. To fake token usage, pass a `Laravel\Ai\Responses\StructuredTextResponse` with a `TextUsage` and `Meta`. `fakeReceiptPhoto()` is a real 1×1 JPEG (use it for shelf and equipment photos too) (there's no GD for `UploadedFile::fake()->image()`). Action modal content isn't in Livewire test HTML: assert schema state, not `assertSee`. To edit repeater rows in a test, pass `fillForm()` a closure: a dotted key such as `changes.0.quantity` replaces the whole `changes` array. Once photos are uploaded, fill other fields with a plain array instead (a closure would re-fill the uploaded files and fail). A simple repeater's raw state holds each item under its field name: fill steps as `[['step' => 'Mix.'], ...]` (and `$set()` them that way); dehydrated `$data` is a flat list. The inventory item factory picks a random location, so set it when a test reads it back. Tick a table checkbox column with `->call('updateTableColumnState', 'bought', (string) $id, true)`.
- MCP tools are tested with `MitchenServer::actingAs($user)->tool(Tool::class, [...])` and `assertStructuredContent(fn (AssertableJson $json) => ...)` or `assertHasErrors([...])`; HTTP tests of `/mcp` use `Passport::actingAs()`. Passport's token guard needs keys even to refuse a request, so `tests/Feature/Mcp/MitchenServerTest.php` generates a key pair into `passport.private_key`/`public_key` in `beforeEach()`; it also runs the whole OAuth handshake (register, approve, token, tool call).
- `php artisan migrate:fresh --seed` loads `DemoKitchenSeeder` (login `test@example.com` / `password`).

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
