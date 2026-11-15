<?php

namespace App\Support;

use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Models\InventoryItem;
use BackedEnum;
use Carbon\CarbonImmutable;

/**
 * One inventory change read from a quick update, waiting for the cook to confirm it.
 */
final readonly class ProposedChange
{
    public function __construct(
        public ChangeKind $kind,
        public string $ingredient,
        public ?string $quantity = null,
        public StorageLocation $location = StorageLocation::Pantry,
        public ?int $inventoryItemId = null,
        public IngredientCategory $category = IngredientCategory::Other,
        public ?CarbonImmutable $expiresOn = null,
        public string $fromNote = '',
        public ?float $price = null,
        public ?CarbonImmutable $readyOn = null,
    ) {}

    /**
     * The cook confirmed an item is gone, such as one a stock-take photo didn't show.
     */
    public static function useUp(InventoryItem $item): self
    {
        return new self(
            kind: ChangeKind::UsedUp,
            ingredient: $item->ingredient->name,
            location: $item->location,
            inventoryItemId: $item->id,
            category: $item->ingredient->category ?? IngredientCategory::Other,
        );
    }

    /**
     * Rebuild a change from review form state. Disabled fields (the quantity of something used
     * up, the location of something needed, the price of anything not bought) aren't in it.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            kind: self::enum(ChangeKind::class, $state['kind']),
            ingredient: trim((string) $state['ingredient']),
            quantity: filled($state['quantity'] ?? null) ? (string) $state['quantity'] : null,
            location: self::enum(StorageLocation::class, $state['location'] ?? null, StorageLocation::Pantry),
            inventoryItemId: filled($state['inventory_item_id'] ?? null) ? (int) $state['inventory_item_id'] : null,
            category: self::enum(IngredientCategory::class, $state['category'] ?? null, IngredientCategory::Other),
            expiresOn: filled($state['expires_on'] ?? null) ? CarbonImmutable::parse($state['expires_on'])->startOfDay() : null,
            fromNote: (string) ($state['from_note'] ?? ''),
            price: is_numeric($state['price'] ?? null) ? round((float) $state['price'], 2) : null,
            readyOn: filled($state['ready_on'] ?? null) ? CarbonImmutable::parse($state['ready_on'])->startOfDay() : null,
        );
    }

    /**
     * @return array{kind: string, ingredient: string, quantity: string|null, location: string, inventory_item_id: int|null, category: string, expires_on: string|null, from_note: string, price: float|null, ready_on: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'ingredient' => $this->ingredient,
            'quantity' => $this->quantity,
            'location' => $this->location->value,
            'inventory_item_id' => $this->inventoryItemId,
            'category' => $this->category->value,
            'expires_on' => $this->expiresOn?->toDateString(),
            'from_note' => $this->fromNote,
            'price' => $this->price,
            'ready_on' => $this->readyOn?->toDateString(),
        ];
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  TEnum|null  $default
     * @return TEnum
     */
    private static function enum(string $enum, mixed $value, ?BackedEnum $default = null): BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return $default === null ? $enum::from($value) : ($enum::tryFrom((string) $value) ?? $default);
    }
}
