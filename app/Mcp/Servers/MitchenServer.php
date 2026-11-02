<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddToShoppingList;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\GetShoppingList;
use App\Mcp\Tools\ListEquipment;
use App\Mcp\Tools\ListInventory;
use App\Mcp\Tools\RemoveFromShoppingList;
use App\Mcp\Tools\RestockList;
use App\Mcp\Tools\UpdateKitchen;
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
    - Quantities are text, written the way people say them: "2 lb", "1 1/2 cups", "half a jar", "3 cans", "a dozen", "6", or a level: "full", "half", "low", "out". Mitchen reads them and says so when it can't.
    - Places: pantry, fridge, freezer, spice_rack, counter.
    - To change what's on hand or the shopping list, work out the changes, read them back to the cook as a short list, and call `update-kitchen` once they agree. Nothing changes until then. Use the catalog's names (`list-inventory`); a new name is added as a one-off ingredient.
    - `what-to-cook` says how close each recipe is with what's in the kitchen; `get-recipe` gives one recipe with what's on hand for each ingredient, scaled or made as a variant.
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
        UpdateKitchen::class,
        AddToShoppingList::class,
        RemoveFromShoppingList::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
