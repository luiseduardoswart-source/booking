<?php

namespace App\Filament\Forms\Components;

use App\Filament\Resources\Amenities\AmenityResource;
use App\Models\Amenity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

/**
 * Choose amenities the way Airbnb does: search, browse by category and a
 * toggle per amenity. Saving goes through the CheckboxList relationship,
 * so exactly like a regular checkbox list.
 */
class AmenitiesPicker extends CheckboxList
{
    protected string $view = 'filament.forms.amenities-picker';

    /** After a new amenity is created, for example to save straight away. */
    protected ?Closure $afterNewAmenity = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relationship('amenities', 'name');
        $this->hintAction($this->newAmenityAction());
    }

    public function afterNewAmenity(?Closure $callback): static
    {
        $this->afterNewAmenity = $callback;

        return $this;
    }

    /**
     * All amenities for the picker, by name in the admin language.
     *
     * @return list<array{id: string, name: string, icon: string, category: string}>
     */
    public function getAmenities(): array
    {
        return Amenity::query()->get()
            ->map(fn (Amenity $amenity) => [
                'id' => (string) $amenity->getKey(),
                'name' => $amenity->localizedName(),
                'icon' => $amenity->iconName(),
                'category' => $amenity->resolvedCategory(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * The categories that contain something, in the fixed order.
     *
     * @param  list<array{category: string}>  $amenities  from getAmenities()
     * @return array<string, string>
     */
    public function getCategories(array $amenities): array
    {
        $used = array_unique(array_column($amenities, 'category'));

        return array_intersect_key(Amenity::categoryOptions(), array_flip($used));
    }

    /** An amenity that is not in the list yet; it is ticked straight away. */
    private function newAmenityAction(): Action
    {
        return Action::make('newAmenity')
            ->label(__('New amenity'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => AmenityResource::canCreate())
            ->modalSubmitActionLabel(__('Add'))
            ->schema([
                TextInput::make('name')
                    ->label(__('Name (English)'))
                    ->hint(__('You set translations under Amenities'))
                    ->required()
                    ->maxLength(Amenity::MAX_NAME)
                    // Not ->unique(): that ignores the form's record, the accommodation.
                    ->rule(Rule::unique('amenities', 'name')),
                Select::make('category')
                    ->label(__('Category'))
                    ->options(fn () => Amenity::categoryOptions())
                    ->default(Amenity::OTHER)
                    ->required(),
                IconPicker::make('icon'),
            ])
            ->action(function (array $data, Get $get, Set $set, object $livewire): void {
                $amenity = Amenity::create($data);
                $previous = array_map('strval', (array) $get($this->getStatePath(false)));
                $chosen = [...$previous, (string) $amenity->getKey()];
                $set($this->getStatePath(false), $chosen);

                $this->evaluate($this->afterNewAmenity, [
                    'chosen' => $chosen,
                    'previous' => $previous,
                    'livewire' => $livewire,
                    'component' => $this,
                ]);
            });
    }
}
