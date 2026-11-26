<?php

namespace App\Support;

/**
 * Changes to the kitchen an assistant worked out with the cook, checked against the catalog
 * (PrepareKitchenChanges): the changes, the shopping list entries they bought, and where and
 * when the bought things were bought.
 */
final readonly class KitchenUpdate
{
    /**
     * @param  list<ProposedChange>  $changes
     * @param  list<int>  $boughtFromList  Shopping list item ids.
     */
    public function __construct(
        public array $changes,
        public array $boughtFromList = [],
        public PurchaseDetails $purchase = new PurchaseDetails,
    ) {}
}
