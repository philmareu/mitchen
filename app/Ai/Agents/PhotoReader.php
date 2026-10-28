<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Stringable;

/**
 * Reads photos into the same changes as KitchenUpdateReader: a grocery receipt becomes
 * "bought" changes with prices and the store, date and total, and a shelf, the fridge or the pantry becomes a stock-take of "set"
 * changes plus the items it expected to see but couldn't (not_seen).
 *
 * Runs on the default (most capable) model, since receipts are terse and labels are small;
 * PHP attributes aren't inherited, so the parent's model choice doesn't apply.
 */
#[MaxTokens(16_000)]
#[Timeout(180)]
class PhotoReader extends KitchenUpdateReader
{
    public function instructions(): Stringable|string
    {
        return parent::instructions().<<<'TEXT'


        This time the cook has attached photos, sometimes with a short note. Each photo is either a grocery receipt or a look at where food is kept: a shelf, the fridge, the freezer, a cupboard, the pantry. Handle each by what it shows. When the note says something about what's in the photos (for example, where things were put away), the note wins.

        Receipts (one receipt may span several photos). Every food or drink item on a receipt was bought:
        - Add one "bought" change per product. Combine repeated lines for the same product.
        - Turn store abbreviations into plain ingredient names ("BNLS SKNLS CHKN THGH" is "Chicken thighs", "ORG BANANAS" is "Bananas"), matching catalog ingredients as above.
        - quantity comes from the receipt: a weighed line ("2.13 lb @ 3.99/lb") gives "2.13 lb"; a count ("2 @ 0.99") gives "2"; a packaged item gives its container ("1 jar", "2 cans", "1 box", "1 bag", "1 carton"). Use "1" when you can't tell.
        - from_note is the receipt line as printed.
        - price is what the line cost (for combined lines, their sum), less any discount or coupon printed right under it for that product. Null when the line shows no price.
        - Add the list id of every shopping list entry a receipt line covers to bought_from_list, including things that aren't food.
        - Leave out lines that aren't food or drink to keep at home (bags, deposits, tax, discounts, coupons, cleaning and household supplies, food eaten at the store) and list them in not_understood by name, without prices.
        - purchase comes from the receipt: store from its header, purchased_on from its date, total is the final amount paid, tax included. A receipt photo of only part of a long receipt may not show the total: leave it null rather than adding up lines.

        Photos of a shelf, the fridge, the freezer, a cupboard or the pantry are a stock-take of what's there right now:
        - Add one "set" change per food or drink you can identify, with how much you can see. Count packages ("3 cans", "2 cartons", "6" eggs). For jars, bottles, bags and boxes, judge how full they are: "1 jar" when full or sealed, otherwise a fraction such as "3/4 jar", "half a bag" or "1/4 bottle". Several of the same thing add up ("1 1/2 jars").
        - Read the labels on jars, tins and packages to name things, and match them to catalog names as above. Name an unlabeled container only when its contents make it obvious (pasta shapes, rice, dried beans); otherwise describe it in not_understood, such as "unlabeled jar of white powder, top shelf".
        - location is the place the photo shows. When the catalog has an item of that ingredient in that place, give its inventory_item_id; an item kept somewhere else is a different item, so leave the id null.
        - from_note says where you saw it ("top shelf, left") or the label as written.
        - expires_on only when a date is printed and legible, otherwise null.
        - Skip things that aren't food or drink, and don't guess at what's hidden behind other things.
        - Never mark something used up because you can't see it. Instead, put in not_seen the item ids of catalog items kept in a place the photos show completely, when they would be visible if they were there but aren't. Leave not_seen empty for a place the photos only show part of.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'not_seen' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
