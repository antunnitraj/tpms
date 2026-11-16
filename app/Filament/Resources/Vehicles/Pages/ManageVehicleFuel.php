<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\FuelUnit;
use App\Filament\Actions\QuickAddActions;
use App\Filament\Resources\Vehicles\Widgets\FuelStatsOverview;
use App\Filament\Schemas\FuelRecordForm;
use App\Models\AppSetting;
use App\Models\FuelRecord;
use App\Services\EconomyFormatter;
use App\Services\FuelEconomy;
use App\Services\VehicleStats;
use App\Support\Format;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageVehicleFuel extends VehicleEntriesPage
{
    protected static string $relationship = 'fuelRecords';

    protected static string|\BackedEnum|null $navigationIcon = 'tpms-fuel';

    /** @var array<int, array{distance: ?int, economy: ?float}>|null */
    protected ?array $economy = null;

    public static function getNavigationLabel(): string
    {
        return __('Fuel');
    }

    protected function getHeaderActions(): array
    {
        $electric = $this->vehicle()->fuelUnit() === FuelUnit::KilowattHours;

        return [QuickAddActions::fuel($this->vehicle())->label($electric ? __('Add charge') : __('Add fuel-up'))->icon('heroicon-o-plus')->labeledFrom('sm')];
    }

    protected function getHeaderWidgets(): array
    {
        return [FuelStatsOverview::class];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(FuelRecordForm::components($this->vehicle()))->columns(1);
    }

    /** Economy for each fill-up, computed once per request over the whole history. */
    public function economyFor(FuelRecord $record): array
    {
        $vehicle = $this->vehicle();
        $this->economy ??= FuelEconomy::calculate($vehicle->fuelRecords()->get(), $vehicle->fuelUnit() === FuelUnit::KilowattHours)['rows'];

        return $this->economy[$record->getKey()] ?? ['distance' => null, 'economy' => null];
    }

    public function table(Table $table): Table
    {
        $vehicle = $this->vehicle();
        $distanceUnit = $vehicle->distanceUnit();
        $fuelUnit = $vehicle->fuelUnit();
        $format = auth()->user()->economyFormat();

        return $table
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('quantity')->weight(FontWeight::SemiBold)
                            ->formatStateUsing(function (FuelRecord $record) use ($fuelUnit, $distanceUnit) {
                                $distance = $this->economyFor($record)['distance'];

                                return collect([
                                    Format::quantity($record->quantity, $fuelUnit),
                                    $distance ? __(':distance driven', ['distance' => Format::distance($distance, $distanceUnit)]) : null,
                                ])->filter()->implode(' · ');
                            }),
                        TextColumn::make('date')->sortable()->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn (FuelRecord $record) => collect([
                                $record->date->toFormattedDateString(),
                                $record->mileage ? Format::distance($record->mileage, $distanceUnit) : null,
                                $record->is_full ? null : __('partial'),
                                $record->missed_previous ? ($fuelUnit === FuelUnit::KilowattHours ? __('after a missed charge') : __('after a missed fill-up')) : null,
                            ])->filter()->implode(' · ')),
                        TextColumn::make('economy')->badge()->color('info')
                            ->state(fn (FuelRecord $record) => Format::economy(EconomyFormatter::format($this->economyFor($record)['economy'], $distanceUnit, $fuelUnit, $format)))
                            ->hidden(fn (?FuelRecord $record) => $record && $this->economyFor($record)['economy'] === null),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('cost')->money(fn () => AppSetting::currency())->weight(FontWeight::SemiBold)->alignEnd()->sortable(),
                        TextColumn::make('unit_price')->size(TextSize::Small)->color('gray')->alignEnd()
                            ->state(fn (FuelRecord $record) => $record->unitPrice() !== null ? Format::money($record->unitPrice()).'/'.$fuelUnit->short() : null),
                    ])->grow(false),
                ]),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('mileage'))
            ->filters([
                SelectFilter::make('year')
                    ->label(__('Year'))
                    ->options(fn () => collect(VehicleStats::years($vehicle))->mapWithKeys(fn ($y) => [$y => $y]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $year) => $q->whereYear('date', $year))),
            ])
            ->recordAction('edit')
            ->recordActions([
                ActionGroup::make([EditAction::make()->slideOver(), DeleteAction::make()]),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading(fn () => $this->emptyHeading(__('No fuel-ups yet')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Log fill-ups to see fuel economy and fuel cost.')))
            ->emptyStateIcon('tpms-fuel');
    }
}
