<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddEquipment;
use App\Mcp\Tools\AddRecipe;
use App\Mcp\Tools\AddToShoppingList;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\GetShoppingList;
use App\Mcp\Tools\ListEquipment;
use App\Mcp\Tools\ListInventory;
use App\Mcp\Tools\LogCook;
use App\Mcp\Tools\RecipeHistory;
use App\Mcp\Tools\RemoveFromShoppingList;
use App\Mcp\Tools\RestockList;
use App\Mcp\Tools\UpdateIngredient;
use App\Mcp\Tools\UpdateKitchen;
use App\Mcp\Tools\UpdateRecipe;
use App\Mcp\Tools\UseSoon;
use App\Mcp\Tools\WhatToCook;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Mitchen')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    Mitchen is the cook's kitchen assistant: the ingredients they keep and how much of each is on hand and where, the shopping list, their recipes and their equipment. Everyone who signs in shares the one kitchen.

    - Core ingredients are staples the cook always keeps: when one runs low or out it goes on the restock list (`restock-list`). Other ingredients are one-offs for a recipe and are removed once used up.
    - A core ingredient can have a restock point (when it goes on the restock list, instead of low or out) and a stock target (how much the cook stocks up to: "10 lb", "4 cans"). With a target, `restock-list` says how much to buy (`to_buy`) and the shopping list fills that in. When the cook wants to keep more or less of something, or makes something a staple, set it with `update-ingredient`.
    - Quantities are text, written the way people say them: "2 lb", "1 1/2 cups", "half a jar", "3 cans", "a dozen", "6", or a level: "full", "half", "low", "out". Mitchen reads them and says so when it can't.
    - Places: pantry, fridge, freezer, spice_rack, counter.
    - To change what's on hand or the shopping list, work out the changes, read them back to the cook as a short list, and call `update-kitchen` once they agree. Nothing changes until then. Use the catalog's names (`list-inventory`); a new name is added as a one-off ingredient.
    - To order online, for delivery or pickup: `get-shopping-list` gives each thing to buy a link for every store with an online shop (`find_online`) that looks it up there. When you can use the cook's browser, open the links, add what fits best to that store's cart (ask when it isn't clear which), then read the cart and its total back. Checking out and paying are the cook's: never place the order. What's ordered stays on the list until it arrives, then goes in as bought with `update-kitchen`.
    - `what-to-cook` says how close each recipe is with what's in the kitchen; `get-recipe` gives one recipe with what's on hand for each ingredient, scaled or made as a variant.
    - `add-recipe` saves a recipe from a web page, a pasted recipe or a photo of a card, copied as written; read it back first, like kitchen changes. After the cook makes something, offer to log it with `log-cook`, with how it turned out and what to change next time.
    - Recipes call for equipment by name and count it as there once the kitchen has a piece of that name. `list-equipment` lists what they call for that isn't there (`not_in_kitchen`); when the cook says they have something, add it with `add-equipment`, named the way the recipes call it.
    - Recipes get better over versions: when the cook wants to work their next-time notes in (`get-recipe` lists those waiting) or change a recipe, use `update-recipe`. It previews by default: show the cook the changes it lists, and save with `preview: false` once they agree. `recipe-history` shows earlier versions.
    - Dates use the YYYY-MM-DD format.
    MARKDOWN)]
class MitchenServer extends Server
{
    protected array $tools = [
        ListInventory::class,
        RestockList::class,
        UseSoon::class,
        GetShoppingList::class,
        WhatToCook::class,
        GetRecipe::class,
        ListEquipment::class,
        AddEquipment::class,
        UpdateKitchen::class,
        UpdateIngredient::class,
        AddToShoppingList::class,
        RemoveFromShoppingList::class,
        AddRecipe::class,
        LogCook::class,
        UpdateRecipe::class,
        RecipeHistory::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
