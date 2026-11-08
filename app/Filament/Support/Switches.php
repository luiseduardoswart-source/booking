<?php

namespace App\Filament\Support;

use App\Filament\Concerns\SwitchesDirect;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * On/off switches that save immediately, as you would expect from a
 * switch: in lists and in forms. The rest of a form keeps waiting for Save.
 */
class Switches
{
    /**
     * A switch in a resource's table. Whoever may not edit the record sees
     * it disabled; Filament also refuses the save then.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function column(string $field, string $label, string $resource): ToggleColumn
    {
        return ToggleColumn::make($field)
            ->label($label)
            ->disabled(fn (Model $record): bool => ! $resource::canEdit($record))
            ->afterStateUpdated(fn () => static::notifySaved());
    }

    /**
     * A switch (or another on/off field, such as the amenities picker) in
     * a form that saves immediately. $save receives the new state and the
     * page, and returns whether anything was saved; if not (for example a
     * record that does not exist yet), the state simply goes along with
     * Save or Create.
     *
     * @template T of Field
     *
     * @param  T  $field
     * @param  Closure(mixed, Component, T): bool  $save
     * @return T
     */
    public static function direct(Field $field, Closure $save): Field
    {
        $normalizeState = fn (mixed $value) => $field instanceof Toggle ? (bool) $value : $value;

        return $field
            ->live()
            ->afterStateUpdated(function (Field $component, mixed $state, mixed $old, Component $livewire) use ($save, $normalizeState): void {
                if (! $save($normalizeState($state), $livewire, $component)) {
                    return;
                }

                static::rememberSaved($livewire, $component->getStatePath(), $normalizeState($old));
                static::notifySaved();
            });
    }

    /** No unsaved-changes warning for what has already been saved. */
    public static function rememberSaved(Component $livewire, string $statePath, mixed $previous): void
    {
        if (in_array(SwitchesDirect::class, class_uses_recursive($livewire), true)) {
            $livewire->rememberAfterDirectSave($statePath, $previous);
        }
    }

    public static function notifySaved(): void
    {
        Notification::make()
            ->title(__('Saved'))
            ->success()
            ->send();
    }
}
