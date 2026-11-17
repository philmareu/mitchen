<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Says which listed name, if any, a name new to one of the kitchen's lists means (its
 * ingredient catalog or its equipment): a misspelling, another name for it, or a generic or
 * brand name for the one kind listed. Only a suggestion: the cook picks (SuggestCatalogNames).
 */
#[UseCheapestModel]
#[MaxTokens(2_000)]
#[Timeout(30)]
class NameMatcher implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You help keep a home kitchen's lists free of duplicates. You get names a recipe or a note uses that aren't on one of the kitchen's lists yet, and that list: its ingredient catalog or its equipment. For each new name, say which listed name the cook most likely means, if any:
        - the same thing misspelled or spelled another way ("cilantor", "jalapeno" for "Jalapeño", "chilies" for "Chili peppers", "cast-iron pan" for "Cast iron skillet");
        - the same thing under another name ("scallions" for "Green onions", "coriander leaves" for "Cilantro", "garbanzo beans" for "Chickpeas", "sheet tray" for "Sheet pan", "crock pot" for "Slow cooker");
        - a generic or brand name for the one kind the list has ("salt" when the catalog's only salt is "Kosher salt", "onion" for "Yellow onions", "Vitamix" for "Blender", "blender" for "Vitamix", "Instant Pot" for "Pressure cooker").

        Never a different thing, however close: masa harina is not cornmeal, baking soda is not baking powder, lemon juice is not lemons, chicken thighs are not chicken breasts, an immersion blender is not a blender, a stand mixer is not a hand mixer, a Dutch oven is not a stockpot, a loaf pan is not a cake pan. When unsure, or when the list has several that could fit, give null. catalog_name must be copied exactly from the list.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'matches' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'catalog_name' => $schema->string()->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
