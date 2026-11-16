<?php

namespace App\Filament\Schemas;

use App\Enums\PlanPriority;
use App\Enums\PlanStatus;
use App\Enums\RecordCategory;
use App\Models\Vehicle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

class PlanForm
{
    public static function components(Vehicle $vehicle): array
    {
        return [
            TextInput::make('description')->label(__('What'))->required()->maxLength(255),
            ToggleButtons::make('category')->label(__('Becomes'))->inline()->required()
                ->default(fn () => static::categories(null)[0])
                ->options(fn (Get $get) => collect(static::categories($get('category')))->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()]))
                ->icons(collect(RecordCategory::plannable())->mapWithKeys(fn ($c) => [$c->value => $c->getIcon()]))
                ->colors(collect(RecordCategory::plannable())->mapWithKeys(fn ($c) => [$c->value => $c->getColor()])),
            Grid::make(['default' => 2])->schema([
                ToggleButtons::make('priority')->label(__('Priority'))->options(PlanPriority::class)->inline()->default(PlanPriority::Normal)->required(),
                ToggleButtons::make('status')->label(__('Status'))->inline()->default(PlanStatus::Planned)->required()
                    ->options([PlanStatus::Planned->value => PlanStatus::Planned->getLabel(), PlanStatus::Doing->value => PlanStatus::Doing->getLabel()]),
            ]),
            Fields::money('cost', __('Estimated cost')),
            Select::make('reminders')->label(__('Completes reminders'))->multiple()
                ->relationship('reminders', 'description', fn ($query) => $query->where('vehicle_id', $vehicle->getKey())->whereNull('completed_at'))
                ->preload(),
            Fields::notes(),
            Fields::suppliesUsed($vehicle),
            Fields::attachments(),
        ];
    }

    /**
     * Plannable types the user works with (all of them if none), plus the plan's own type when editing.
     *
     * @return list<RecordCategory>
     */
    private static function categories(mixed $current): array
    {
        $current = $current instanceof RecordCategory ? $current : RecordCategory::tryFrom((string) $current);
        $mine = array_filter(RecordCategory::plannable(), fn (RecordCategory $c) => in_array($c, auth()->user()->recordCategories(), true) || $c === $current);

        return array_values($mine ?: RecordCategory::plannable());
    }
}
