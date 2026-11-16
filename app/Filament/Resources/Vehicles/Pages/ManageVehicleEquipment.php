<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Schemas\EquipmentForm;
use App\Filament\Schemas\Fields;
use App\Models\Equipment;
use App\Models\EquipmentPeriod;
use App\Services\EquipmentUsage;
use App\Services\Odometer;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Things that come and go: tyre sets, roof boxes, trailers. */
class ManageVehicleEquipment extends VehicleEntriesPage
{
    protected static string $relationship = 'equipment';

    protected static string|\BackedEnum|null $navigationIcon = 'tpms-oil';

    protected ?int $currentMileage = null;

    public static function getNavigationLabel(): string
    {
        return __('Equipment');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add equipment'))->modalHeading(__('Add equipment'))->icon('heroicon-o-plus')->labeledFrom('sm')->slideOver()
                ->model(Equipment::class)
                ->schema(fn () => EquipmentForm::components($this->vehicle()))
                ->using(fn (array $data) => $this->vehicle()->equipment()->create($data))
                ->createAnother(false),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(EquipmentForm::components($this->vehicle()))->columns(1);
    }

    public function table(Table $table): Table
    {
        $vehicle = $this->vehicle();
        $unit = $vehicle->distanceUnit();
        $current = fn () => $this->currentMileage ??= Odometer::current($vehicle);
        $periodForm = fn () => [Grid::make(['default' => 2])->schema([Fields::date(), Fields::mileage($vehicle)])];
        // Removing can't happen before installing.
        $removeForm = fn (Equipment $record) => [Grid::make(['default' => 2])->schema([
            Fields::date()->minDate($record->openPeriod?->installed_on?->toDateString() ?? Fields::EARLIEST_DATE),
            Fields::mileage($vehicle)->minValue($record->openPeriod?->installed_mileage ?? 0),
        ])];

        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['periods', 'openPeriod']))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')->weight(FontWeight::SemiBold)->searchable(),
                        TextColumn::make('status')->size(TextSize::Small)->color('gray')
                            ->state(fn (Equipment $record) => $record->openPeriod
                                ? __('Installed since :date', ['date' => $record->openPeriod->installed_on->toFormattedDateString()])
                                : __('Not installed')),
                    ])->space(1),
                    TextColumn::make('distance')->alignEnd()->grow(false)
                        ->state(fn (Equipment $record) => Format::distance(EquipmentUsage::distance($record, $current()), $unit)),
                ]),
            ])
            ->defaultSort('name')
            ->recordAction('edit')
            ->recordActions([
                // Labelled: as bare icons they read as download and share.
                Action::make('install')->label(__('Install'))->icon('heroicon-o-arrow-down-on-square')->button()->size('sm')->color('success')
                    ->modalWidth('md')->schema($periodForm)
                    ->action(fn (Equipment $record, array $data) => EquipmentUsage::install($record, CarbonImmutable::parse($data['date']), filled($data['mileage']) ? (int) $data['mileage'] : null))
                    ->modalSubmitActionLabel(__('Install'))->successNotificationTitle(__('Installed'))
                    ->visible(fn (Equipment $record) => $record->openPeriod === null),
                Action::make('remove')->label(__('Remove'))->icon('heroicon-o-arrow-up-on-square')->button()->size('sm')->color('warning')
                    ->modalWidth('md')->schema($removeForm)
                    ->action(fn (Equipment $record, array $data) => EquipmentUsage::remove($record, CarbonImmutable::parse($data['date']), filled($data['mileage']) ? (int) $data['mileage'] : null))
                    ->modalSubmitActionLabel(__('Remove'))->successNotificationTitle(__('Removed'))
                    ->visible(fn (Equipment $record) => $record->openPeriod !== null),
                ActionGroup::make([
                    Action::make('history')->label(__('History'))->icon('heroicon-o-clock')->slideOver()
                        ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                        ->schema([
                            RepeatableEntry::make('periods')->hiddenLabel()->contained(false)
                                ->schema([
                                    TextEntry::make('installed_on')->hiddenLabel()
                                        ->state(fn (EquipmentPeriod $record) => $record->installed_on->toFormattedDateString().' → '.($record->removed_on?->toFormattedDateString() ?? __('now'))),
                                    TextEntry::make('mileage')->hiddenLabel()->alignEnd()
                                        ->state(fn (EquipmentPeriod $record) => Format::distance($record->installed_mileage, $unit).' → '.Format::distance($record->removed_mileage, $unit)),
                                ])->columns(['default' => 2]),
                        ]),
                    EditAction::make()->slideOver(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn () => $this->emptyHeading(__('No equipment')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Track tyre sets or accessories and the distance driven with each.')))
            ->emptyStateIcon('tpms-oil');
    }
}
