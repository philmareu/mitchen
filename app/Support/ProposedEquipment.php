<?php

namespace App\Support;

use App\Enums\EquipmentCategory;
use App\Enums\EquipmentChangeKind;

/**
 * One piece of equipment read from a note or photos, waiting for the cook to confirm it.
 */
final readonly class ProposedEquipment
{
    /**
     * @param  list<int>  $photos  Which of the attached photos it's in, counting from 0.
     */
    public function __construct(
        public EquipmentChangeKind $kind,
        public string $name,
        public EquipmentCategory $category = EquipmentCategory::Other,
        public ?string $brand = null,
        public ?string $size = null,
        public int $count = 1,
        public ?int $equipmentId = null,
        public array $photos = [],
        public string $fromNote = '',
    ) {}

    /**
     * Rebuild a piece from review form state. Disabled fields (everything but the name of
     * something gone) aren't in it.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $kind = $state['kind'];
        $category = $state['category'] ?? null;

        return new self(
            kind: $kind instanceof EquipmentChangeKind ? $kind : EquipmentChangeKind::from($kind),
            name: trim((string) $state['name']),
            category: $category instanceof EquipmentCategory ? $category : (EquipmentCategory::tryFrom((string) $category) ?? EquipmentCategory::Other),
            brand: filled($state['brand'] ?? null) ? trim((string) $state['brand']) : null,
            size: filled($state['size'] ?? null) ? trim((string) $state['size']) : null,
            count: max(1, (int) ($state['count'] ?? 1)),
            equipmentId: filled($state['equipment_id'] ?? null) ? (int) $state['equipment_id'] : null,
            photos: array_values(array_map(intval(...), $state['photos'] ?? [])),
            fromNote: (string) ($state['from_note'] ?? ''),
        );
    }

    /**
     * @return array{kind: string, name: string, category: string, brand: string|null, size: string|null, count: int, equipment_id: int|null, photos: list<string>, from_note: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'category' => $this->category->value,
            'brand' => $this->brand,
            'size' => $this->size,
            'count' => $this->count,
            'equipment_id' => $this->equipmentId,
            // Select options are keyed by strings in form state.
            'photos' => array_map(strval(...), $this->photos),
            'from_note' => $this->fromNote,
        ];
    }
}
