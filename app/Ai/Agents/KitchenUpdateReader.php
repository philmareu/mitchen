<?php

namespace App\Ai\Agents;

use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a quick, plain-language note about the kitchen ("bought 2 lbs chicken,
 * out of milk, half a bag of rice left, need eggs") into inventory and shopping
 * list changes for review.
 */
#[UseCheapestModel]
#[MaxTokens(4_000)]
#[Timeout(60)]
class KitchenUpdateReader implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Whether the default AI provider has an API key to call it with.
     */
    public static function isConfigured(): bool
    {
        return filled(config('ai.providers.'.config('ai.default').'.key'));
    }

    public function instructions(): Stringable|string
    {
        $units = collect(Unit::cases())->map(fn (Unit $unit): string => $unit->getLabel())->join(', ');
        $locations = collect(StorageLocation::cases())->map(fn (StorageLocation $location): string => $location->value)->join(', ');

        return <<<TEXT
        You keep a home kitchen's inventory and shopping list up to date. The cook sends a quick note about what changed, written the way people talk, and you turn it into a list of changes they will review before anything is saved.

        You also see today's date, every ingredient in their catalog with what's on hand right now (item ids, amounts, locations, use-by dates, and when something still ripening will be ready), and the shopping list: things still to buy, with list ids. Core ingredients are staples they always keep stocked; some have a restock point, the amount at which they go on the restock list.

        For each ingredient the note mentions, add one change:
        - kind "bought": new supply came in (bought, picked up, got, was given, made a batch of). quantity is how much came in.
        - kind "set": how much is left now ("half a bag of rice left", "low on olive oil", "only 2 eggs left", "plenty of flour").
        - kind "used_up": finished, empty, ran out, or thrown away. quantity is null.
        - kind "need": to buy on the next shop ("need eggs", "put paper towels on the list", "we should get more rice"). quantity is how much to get, or null. Household things that aren't food, like paper towels, are fine here. Running out of something isn't "need" unless the note says to get more.

        Fields:
        - ingredient: if it's in the catalog, its exact catalog name, matching loosely ("PB" is "Peanut butter"; "rice" is "Jasmine rice" when that's the only rice). Otherwise a clean name in sentence case, such as "Chicken thighs" or "Greek yogurt", without brand names or amounts.
        - inventory_item_id: for "set" and "used_up", the id of the item the note is about. If the ingredient has several items and the note doesn't say which, pick the one that's opened or running low. Null for "bought" and when nothing is on hand.
        - quantity: short and plain, like "2 lb", "500 g", "1 1/2 cups", "half a jar", "3 cans", "a dozen", "6", or a level: "full", "about half", "low", "out". Use only these units: {$units}. For countable things with no unit, give just the number. When the catalog already measures the ingredient in a unit (on its items or its restock point) and that unit fits, use it, so amounts stay comparable. Null when the note gives no amount.
        - location: one of {$locations}. For existing items, where the item already is. For new supply, where the note says, otherwise where it's normally kept (fresh meat and dairy in the fridge, frozen food in the freezer, dry goods in the pantry, dried spices on the spice rack, whole fruit, onions and garlic on the counter).
        - category: the ingredient's category.
        - expires_on: a YYYY-MM-DD use-by date only if the note gives one ("use by Friday" means the coming Friday). Otherwise null. Never guess a shelf life.
        - ready_on: for "bought" and "set", a YYYY-MM-DD date when it's in the kitchen but not ready to use yet: fruit or vegetables bought underripe or left to ripen ("4 hard avocados", "peppers to get really ripe, about a week"). Use the time the note gives; when it only says they're unripe, a typical time for that produce where it's kept (hard avocados on the counter about 4 days; in the fridge they barely ripen, about 10). Today's date when the note says something that was ripening is ready now ("avocados are ripe"). Otherwise null.
        - from_note: the few words of the note this change comes from.
        - price: for "bought" only, what was paid for it in total, as a plain number (12.5, not "$12.50"), when the note says. Otherwise null. Never guess a price.

        bought_from_list: the list ids of shopping list entries this update shows were bought, including things that aren't food. Empty when none were.

        purchase: where and when the "bought" things were bought, as far as the note says.
        - store: the store's name. When it's one of the cook's stores (listed with the catalog), use that exact name ("Costco" for "COSTCO WHOLESALE #482"); otherwise a plain name such as "Trader Joe's". Null when not said.
        - purchased_on: YYYY-MM-DD when the note says when ("yesterday", a date). Null for today or when not said.
        - total: the total paid for the shop, as a plain number, when stated. Otherwise null.
        All null when nothing was bought.

        Leave out anything that isn't about food or drink on hand or the shopping list, such as reminders or recipes to try, and list those words in not_understood. Put anything you can't confidently turn into a change there too. Never invent changes the note doesn't support.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        $change = $schema->object([
            'kind' => $schema->string()->enum(ChangeKind::class)->required(),
            'ingredient' => $schema->string()->required(),
            'inventory_item_id' => $schema->integer()->nullable()->required(),
            'quantity' => $schema->string()->nullable()->required(),
            'location' => $schema->string()->enum(StorageLocation::class)->required(),
            'category' => $schema->string()->enum(IngredientCategory::class)->required(),
            'expires_on' => $schema->string()->nullable()->required(),
            'ready_on' => $schema->string()->nullable()->required(),
            'from_note' => $schema->string()->required(),
            'price' => $schema->number()->nullable()->required(),
        ])->withoutAdditionalProperties();

        $purchase = $schema->object([
            'store' => $schema->string()->nullable()->required(),
            'purchased_on' => $schema->string()->nullable()->required(),
            'total' => $schema->number()->nullable()->required(),
        ])->withoutAdditionalProperties();

        return [
            'changes' => $schema->array()->items($change)->required(),
            'bought_from_list' => $schema->array()->items($schema->integer())->required(),
            'purchase' => $purchase->required(),
            'not_understood' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
