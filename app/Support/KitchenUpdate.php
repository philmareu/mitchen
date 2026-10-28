<?php

namespace App\Support;

/**
 * What the assistant made of a quick update: changes to review, shopping list entries it
 * thinks were bought, items a stock-take photo should have shown but didn't, anything it
 * couldn't place, and where and when the bought things were bought.
 */
final readonly class KitchenUpdate
{
    /**
     * @param  list<ProposedChange>  $changes
     * @param  list<string>  $notUnderstood
     * @param  list<int>  $boughtFromList  Shopping list item ids.
     * @param  list<int>  $notSeen  Inventory item ids, for the cook to confirm as used up.
     */
    public function __construct(
        public array $changes,
        public array $notUnderstood = [],
        public array $boughtFromList = [],
        public array $notSeen = [],
        public PurchaseDetails $purchase = new PurchaseDetails,
    ) {}
}
