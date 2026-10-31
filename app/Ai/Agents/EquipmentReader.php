<?php

namespace App\Ai\Agents;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentChangeKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads photos of kitchen equipment and/or a note ("got a 7 qt Dutch oven, the blender
 * died") into pieces to add, update or remove, for review.
 *
 * Runs on the default (most capable) model: telling a ricer from a garlic press and reading
 * a brand off a mixer takes good eyes, and equipment changes too rarely for the cost to matter.
 */
#[MaxTokens(8_000)]
#[Timeout(180)]
class EquipmentReader implements Agent, HasStructuredOutput
{
    use Promptable;

    public static function isConfigured(): bool
    {
        return KitchenUpdateReader::isConfigured();
    }

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You keep track of the equipment in a home kitchen: appliances, cookware, bakeware, knives, utensils, single-job tools and gadgets, bowls and measuring tools. The cook sends photos, a short note, or both, and you turn them into a list of equipment they will review before anything is saved.

        You also see the equipment already listed, with ids. The photos are numbered in the order they're attached, starting at 1.

        For each piece of equipment the note or photos show, add one entry:
        - kind "added": a piece that isn't listed yet.
        - kind "existing": a piece that's already listed, such as a new photo of their stand mixer or a note that adds its size.
        - kind "gone": the note says it broke, was given away or thrown out. Never mark something gone because a photo doesn't show it.

        Fields:
        - equipment_id: for "existing" and "gone", the id of the listed piece. Null for "added".
        - name: a plain, generic name in sentence case that says what it is, such as "Stand mixer", "Cast iron skillet", "Dutch oven", "Potato ricer", "Tortilla press", "Ladle", "Sheet pan". No brand, size or count in the name. For a listed piece, its listed name.
        - category: appliances (stand mixer, blender, slow cooker, pressure cooker, sous vide circulator, dehydrator, food processor), cookware (pots, pans, skillets, Dutch ovens, woks), bakeware (sheet pans, loaf and cake pans, muffin tins, baking dishes), knives (knives, cutting boards, sharpeners), utensils (ladles, spatulas, whisks, tongs, wooden spoons, peelers), tools (single-job tools and gadgets: ricer, tortilla press, mandoline, garlic press, thermometer), prep (mixing bowls, measuring cups and spoons, scales, colanders, sieves), or other.
        - brand: the make and model, when the note says or it's legible on the piece ("KitchenAid Artisan", "Lodge", "Le Creuset"). Otherwise null. Never guess a brand from its looks.
        - size: capacity or dimensions, when the note says or it's printed or stamped on the piece ("5 qt", "10 in", "9×13 in", "12 cup"). Otherwise null.
        - count: how many there are. Identical pieces are one entry with a count ("2 sheet pans"); different sizes are separate entries. For a listed piece, its listed count unless the note says how many there are now.
        - photos: the numbers of the photos it appears in. Empty when it's only in the note.
        - from_note: the few words of the note it comes from, or where it is in the photo ("on the counter, left").

        A photo may show one piece or many (a drawer of utensils, a shelf of pans): list each piece you can identify. Include the oven, stove, fridge or microwave only when the note or photo is about them. Don't guess at what's hidden behind other things.

        Leave out anything that isn't about kitchen equipment, such as food or reminders, and list those words in not_understood. Put anything you can't identify there too, described briefly ("black-handled gadget at the back of the drawer"). Never invent equipment the note and photos don't show.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        $piece = $schema->object([
            'kind' => $schema->string()->enum(EquipmentChangeKind::class)->required(),
            'equipment_id' => $schema->integer()->nullable()->required(),
            'name' => $schema->string()->required(),
            'category' => $schema->string()->enum(EquipmentCategory::class)->required(),
            'brand' => $schema->string()->nullable()->required(),
            'size' => $schema->string()->nullable()->required(),
            'count' => $schema->integer()->required(),
            'photos' => $schema->array()->items($schema->integer())->required(),
            'from_note' => $schema->string()->required(),
        ])->withoutAdditionalProperties();

        return [
            'pieces' => $schema->array()->items($piece)->required(),
            'not_understood' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
