<?php

namespace App\Filament\Schemas;

use App\Enums\RecordCategory;
use App\Models\Vehicle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

/** Service, repair, upgrade and tax/fee entries. */
class RecordForm
{
    public static function components(Vehicle $vehicle, bool $creating = true, ?string $defaultCategory = null): array
    {
        return [
            ToggleButtons::make('category')->label(__('Type'))->inline()->required()
                ->options(fn (Get $get) => static::categoryOptions($get('category'))->mapWithKeys(fn (RecordCategory $c) => [$c->value => $c->getLabel()]))
                ->icons(collect(RecordCategory::cases())->mapWithKeys(fn (RecordCategory $c) => [$c->value => $c->getIcon()]))
                ->colors(collect(RecordCategory::cases())->mapWithKeys(fn (RecordCategory $c) => [$c->value => $c->getColor()]))
                ->default(RecordCategory::tryFrom((string) $defaultCategory) ?? auth()->user()->recordCategories()[0])
                // With a single type there is nothing to choose; it is still saved.
                ->hidden(fn (Get $get) => static::categoryOptions($get('category'))->count() === 1)->dehydratedWhenHidden()
                ->live(),
            TextInput::make('description')->label(__('Description'))->required()->maxLength(255)
                ->datalist(fn () => $vehicle->records()->distinct()->orderBy('description')->limit(50)->pluck('description')->all()),
            Grid::make(['default' => 2])->schema([
                Fields::date(),
                Fields::money('cost')->required()->default(0),
            ]),
            Fields::mileage($vehicle)->visible(fn (Get $get) => $get('category') !== RecordCategory::TaxFee->value && $get('category') !== RecordCategory::TaxFee),
            ...($creating ? [
                Select::make('complete_reminders')
                    ->label(__('Completes reminders'))
                    ->multiple()
                    ->options(fn () => $vehicle->reminders()->open()->orderBy('description')->pluck('description', 'id'))
                    ->helperText(__('Selected reminders are marked done and move to their next due date.'))
                    ->visible(fn () => $vehicle->reminders()->open()->exists()),
            ] : []),
            Fields::notes(),
            Fields::suppliesUsed($vehicle),
            Fields::attachments(),
            Section::make(__('More'))
                ->compact()
                ->collapsed()
                ->schema([
                    Fields::tags($vehicle),
                    Fields::links(),
                ]),
        ];
    }

    /** The user's log entry types, plus the entry's own type when editing one that is hidden. */
    private static function categoryOptions(mixed $current): Collection
    {
        $current = $current instanceof RecordCategory ? $current : RecordCategory::tryFrom((string) $current);

        return collect(RecordCategory::cases())
            ->filter(fn (RecordCategory $c) => in_array($c, auth()->user()->recordCategories(), true) || $c === $current)
            ->values();
    }
}
