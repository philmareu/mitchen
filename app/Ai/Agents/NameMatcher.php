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
 * Says which catalog ingredient, if any, a name new to the catalog means: a misspelling,
 * another name for it, or the catalog's kind of a generic name. Only a suggestion: the cook
 * picks (SuggestCatalogNames).
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
        You help keep a home kitchen's ingredient catalog free of duplicates. You get names a recipe or a note uses that aren't in the catalog yet, and the catalog's names. For each new name, say which catalog ingredient the cook most likely means, if any:
        - the same ingredient misspelled or spelled another way ("cilantor", "jalapeno" for "Jalapeño", "chilies" for "Chili peppers");
        - the same ingredient under another name ("scallions" for "Green onions", "coriander leaves" for "Cilantro", "garbanzo beans" for "Chickpeas");
        - a generic name for the one kind the catalog has ("salt" when the catalog's only salt is "Kosher salt", "onion" for "Yellow onions").

        Never a different ingredient, however close: masa harina is not cornmeal, baking soda is not baking powder, lemon juice is not lemons, chicken thighs are not chicken breasts. When unsure, or when the catalog has several kinds that could fit, give null. catalog_name must be copied exactly from the catalog.
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
