<?php

namespace App\Support;

/**
 * What the assistant made of a note or photos about kitchen equipment: pieces to review,
 * and anything it couldn't identify.
 */
final readonly class EquipmentUpdate
{
    /**
     * @param  list<ProposedEquipment>  $pieces
     * @param  list<string>  $notUnderstood
     */
    public function __construct(
        public array $pieces,
        public array $notUnderstood = [],
    ) {}
}
