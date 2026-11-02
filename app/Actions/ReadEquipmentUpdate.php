<?php

namespace App\Actions;

use App\Ai\Agents\EquipmentReader;
use App\Enums\EquipmentCategory;
use App\Enums\EquipmentChangeKind;
use App\Models\Equipment;
use App\Models\RecipeEquipment;
use App\Support\EquipmentUpdate;
use App\Support\ProposedEquipment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;

/**
 * Asks the assistant what's in the photos or note, telling it what the recipes call for that
 * isn't listed so it names those pieces the way the recipes do, then checks its answer against
 * the equipment already listed: ids it invents are dropped (an unknown piece "already had" is
 * new, an unknown piece "gone" is nothing to remove), listed pieces keep their name and
 * category, and photo numbers that weren't attached are ignored.
 */
class ReadEquipmentUpdate
{
    /**
     * @param  list<string>  $photos  Paths to photos of the equipment.
     */
    public function handle(string $note, array $photos = []): EquipmentUpdate
    {
        $equipment = Equipment::query()->orderBy('name')->get();

        $response = EquipmentReader::make()->prompt(
            $this->prompt($note, count($photos), $equipment),
            attachments: array_map(fn (string $path): LocalImage => Image::fromPath($path), $photos),
        );

        $listed = $equipment->keyBy('id');

        $pieces = collect($this->list($response['pieces'] ?? null))
            ->map(fn (mixed $piece): ?ProposedEquipment => is_array($piece) ? $this->piece($piece, $listed, count($photos)) : null)
            ->filter()
            ->values()
            ->all();

        $notUnderstood = collect($this->list($response['not_understood'] ?? null))
            ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
            ->map(fn (string $text): string => trim($text))
            ->values()
            ->all();

        return new EquipmentUpdate($pieces, $notUnderstood);
    }

    /**
     * @param  array<string, mixed>  $piece
     * @param  BaseCollection<int, Equipment>  $listed
     */
    private function piece(array $piece, BaseCollection $listed, int $photoCount): ?ProposedEquipment
    {
        $kind = EquipmentChangeKind::tryFrom((string) ($piece['kind'] ?? ''));
        $name = trim((string) ($piece['name'] ?? ''));

        if ($kind === null || $name === '') {
            return null;
        }

        $equipment = $kind === EquipmentChangeKind::Added ? null : $listed->get((int) ($piece['equipment_id'] ?? 0));

        if ($equipment === null && $kind === EquipmentChangeKind::Gone) {
            return null;
        }

        $kind = $equipment === null ? EquipmentChangeKind::Added : $kind;

        $count = $piece['count'] ?? null;

        return new ProposedEquipment(
            kind: $kind,
            name: $equipment->name ?? Str::ucfirst($name),
            category: $equipment->category ?? EquipmentCategory::tryFrom((string) ($piece['category'] ?? '')) ?? EquipmentCategory::Other,
            brand: $this->text($piece['brand'] ?? null) ?? $equipment?->brand,
            size: $this->text($piece['size'] ?? null) ?? $equipment?->size,
            count: is_numeric($count) && $count >= 1 ? min((int) $count, 999) : ($equipment->count ?? 1),
            equipmentId: $equipment?->id,
            photos: $kind === EquipmentChangeKind::Gone ? [] : $this->photos($piece['photos'] ?? null, $photoCount),
            fromNote: trim((string) ($piece['from_note'] ?? '')),
        );
    }

    /**
     * The assistant numbers photos from 1; only ones that were attached count.
     *
     * @return list<int> Counting from 0.
     */
    private function photos(mixed $numbers, int $photoCount): array
    {
        return collect($this->list($numbers))
            ->filter(fn (mixed $number): bool => is_int($number) && $number >= 1 && $number <= $photoCount)
            ->map(fn (int $number): int => $number - 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The model's output is untrusted: anything that isn't a list counts as empty.
     *
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  Collection<int, Equipment>  $equipment
     */
    private function prompt(string $note, int $photoCount, Collection $equipment): string
    {
        $listed = $equipment->map(function (Equipment $piece): string {
            $details = collect([$piece->brand, $piece->size, $piece->count > 1 ? "×{$piece->count}" : null])->filter()->join(', ');

            return sprintf(
                '- equipment %d: %s [%s]%s',
                $piece->id,
                $piece->name,
                $piece->category->value ?? 'other',
                $details === '' ? '' : " {$details}",
            );
        })->join("\n") ?: '(nothing listed yet)';

        $wanted = RecipeEquipment::query()
            ->missingFromKitchen()
            ->map(fn (array $recipes, string $name): string => sprintf('- %s (%s)', $name, implode(', ', $recipes)))
            ->join("\n") ?: '(none)';

        $note = trim($note) === '' ? '(no note, just the photos)' : $note;
        $photos = $photoCount === 0 ? 'none' : (string) $photoCount;

        return <<<TEXT
        The cook's note:
        """
        {$note}
        """

        Photos attached: {$photos}

        Equipment already listed:
        {$listed}

        Equipment the recipes call for that isn't listed:
        {$wanted}
        TEXT;
    }
}
