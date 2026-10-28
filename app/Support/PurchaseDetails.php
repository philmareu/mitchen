<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Where and when something was bought and what the shop came to, as far as a receipt or
 * note says. Anything it doesn't say is null: the purchase is then dated today.
 */
final readonly class PurchaseDetails
{
    public function __construct(
        public ?string $store = null,
        public ?CarbonImmutable $purchasedOn = null,
        public ?float $total = null,
    ) {}

    /**
     * Rebuild the details from review form state.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            store: filled($state['store'] ?? null) ? trim((string) $state['store']) : null,
            purchasedOn: filled($state['purchased_on'] ?? null) ? CarbonImmutable::parse($state['purchased_on'])->startOfDay() : null,
            total: is_numeric($state['total'] ?? null) ? round((float) $state['total'], 2) : null,
        );
    }

    /**
     * @return array{store: string|null, purchased_on: string, total: float|null}
     */
    public function toArray(): array
    {
        return [
            'store' => $this->store,
            'purchased_on' => ($this->purchasedOn ?? CarbonImmutable::today())->toDateString(),
            'total' => $this->total,
        ];
    }
}
