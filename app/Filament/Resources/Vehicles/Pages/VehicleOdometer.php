<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Actions\QuickAddActions;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Schemas\OdometerReadingForm;
use App\Models\OdometerReading;
use App\Services\Odometer;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The odometer timeline: every mileage logged anywhere (fuel-ups, log entries
 * and manual readings), with the distance since the previous one.
 */
class VehicleOdometer extends VehicleRecordPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.vehicles.table-page';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    public static function getNavigationLabel(): string
    {
        return __('Odometer');
    }

    protected function getHeaderActions(): array
    {
        return [QuickAddActions::reading($this->vehicle())->label(__('Add reading'))->icon('heroicon-o-plus')->labeledFrom('sm')];
    }

    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        // The timeline isn't an Eloquent query, so Filament doesn't re-read it by itself:
        // without this, an edited or deleted reading would show its old row until a reload.
        $this->flushCachedTableRecords();
    }

    private function reading(array $record): ?OdometerReading
    {
        return $record['source'] === 'odometer_reading'
            ? $this->vehicle()->odometerReadings()->find($record['id'])
            : null;
    }

    public function table(Table $table): Table
    {
        $vehicle = $this->vehicle();
        $unit = $vehicle->distanceUnit();

        return $table
            ->records(function (int $page, int $recordsPerPage) use ($vehicle) {
                $timeline = Odometer::timeline($vehicle)->reverse()->values()
                    ->map(fn (array $row) => $row + ['key' => "{$row['source']}-{$row['id']}"]);

                return new LengthAwarePaginator(
                    $timeline->forPage($page, $recordsPerPage)->keyBy('key')->all(),
                    $timeline->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('mileage')->weight(FontWeight::SemiBold)
                            ->formatStateUsing(fn ($state) => Format::distance($state, $unit)),
                        TextColumn::make('date')->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn (array $record) => collect([
                                $record['date']->toFormattedDateString(),
                                match ($record['source']) {
                                    'fuel_record' => __('Fuel-up'),
                                    'record' => $record['label'],
                                    default => $record['label'] ?: __('Reading'),
                                },
                            ])->filter()->implode(' · ')),
                    ]),
                    TextColumn::make('distance')->alignEnd()->grow(false)->color('gray')
                        ->formatStateUsing(fn ($state) => $state ? '+'.Format::distance($state, $unit) : null),
                ]),
            ])
            // Manual readings open for editing; readings from other entries jump to their list.
            ->recordAction(fn (array $record) => $record['source'] === 'odometer_reading' ? 'editReading' : null)
            ->recordUrl(fn (array $record) => $record['source'] === 'odometer_reading'
                ? null
                : VehicleResource::getUrl($record['source'] === 'fuel_record' ? 'fuel' : 'log', ['record' => $vehicle]))
            ->recordActions([
                ActionGroup::make([
                    Action::make('editReading')
                        ->icon('heroicon-o-pencil-square')
                        ->label(__('Edit'))
                        ->slideOver()
                        ->fillForm(fn (array $record) => $this->reading($record)?->attributesToArray() ?? [])
                        ->schema(fn () => OdometerReadingForm::components($vehicle))
                        ->action(fn (array $record, array $data) => $this->reading($record)?->update($data))
                        ->modalSubmitActionLabel(__('Save changes'))
                        ->successNotificationTitle(__('Saved')),
                    Action::make('deleteReading')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->label(__('Delete'))
                        ->requiresConfirmation()
                        ->action(fn (array $record) => $this->reading($record)?->delete())
                        ->successNotificationTitle(__('Deleted')),
                ])->visible(fn (array $record) => $record['source'] === 'odometer_reading'),
            ])
            ->emptyStateHeading(__('No odometer readings yet'))
            ->emptyStateDescription(__('Mileages from fuel-ups and log entries appear here automatically.'))
            ->emptyStateIcon('heroicon-o-map');
    }
}
