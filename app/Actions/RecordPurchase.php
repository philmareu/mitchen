<?php

namespace App\Actions;

use App\Enums\ChangeKind;
use App\Models\Purchase;
use App\Models\Store;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use Illuminate\Support\Facades\DB;

/**
 * Adds a shop to the purchase history: the store (matched by name, or added), the date,
 * and a line per thing bought with its price when it's known.
 */
class RecordPurchase
{
    /**
     * @param  list<ProposedChange>  $changes  Only the "bought" ones are recorded.
     */
    public function handle(PurchaseDetails $details, array $changes): ?Purchase
    {
        $bought = array_values(array_filter($changes, fn (ProposedChange $change): bool => $change->kind === ChangeKind::Bought));

        if ($bought === []) {
            return null;
        }

        return DB::transaction(function () use ($details, $bought): Purchase {
            $purchase = new Purchase([
                'purchased_on' => $details->purchasedOn ?? today(),
                'total' => $details->total,
            ]);

            $purchase->store()->associate($details->store === null ? null : $this->store($details->store));
            $purchase->save();

            foreach ($bought as $change) {
                $purchase->items()->create([
                    'name' => $change->ingredient,
                    'as_written' => $change->fromNote === '' ? null : $change->fromNote,
                    'quantity' => $change->quantity,
                    'price' => $change->price,
                ]);
            }

            $purchase->addUpMissingTotal();

            return $purchase;
        });
    }

    private function store(string $name): Store
    {
        return Store::query()->named($name)->first() ?? Store::create(['name' => $name]);
    }
}
