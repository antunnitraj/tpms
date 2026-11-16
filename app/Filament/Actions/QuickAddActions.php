<?php

namespace App\Filament\Actions;

use App\Enums\FuelUnit;
use App\Filament\Schemas\FuelRecordForm;
use App\Filament\Schemas\OdometerReadingForm;
use App\Filament\Schemas\RecordForm;
use App\Models\FuelRecord;
use App\Models\OdometerReading;
use App\Models\Record;
use App\Models\Vehicle;
use App\Services\Reminders;
use Closure;
use Filament\Actions\CreateAction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * "Add" actions shared by the garage, the overview and the section pages. Pass the
 * vehicle, or a closure that picks it from the action's arguments (the garage).
 */
class QuickAddActions
{
    /** @param  Vehicle|Closure(array): Vehicle  $vehicle */
    public static function fuel(Vehicle|Closure $vehicle): CreateAction
    {
        $vehicle = static::resolver($vehicle);

        return CreateAction::make('addFuel')
            ->label(__('Fuel'))
            ->icon('tpms-fuel')
            ->modalHeading(fn (array $arguments) => $vehicle($arguments)->fuelUnit() === FuelUnit::KilowattHours ? __('Add charge') : __('Add fuel-up'))
            ->slideOver()
            ->model(FuelRecord::class)
            ->schema(fn (array $arguments) => FuelRecordForm::components($vehicle($arguments)))
            ->using(fn (array $data, array $arguments) => $vehicle($arguments)->fuelRecords()->create($data))
            ->createAnother(false);
    }

    /** @param  Vehicle|Closure(array): Vehicle  $vehicle */
    public static function record(Vehicle|Closure $vehicle, ?string $category = null): CreateAction
    {
        $vehicle = static::resolver($vehicle);

        return CreateAction::make('addRecord')
            ->label(__('Log entry'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->modalHeading(__('Add log entry'))
            ->slideOver()
            ->model(Record::class)
            // A default (not fillForm) so the other defaults — today, last odometer — still apply.
            ->schema(fn (array $arguments) => RecordForm::components($vehicle($arguments), defaultCategory: $category))
            ->using(fn (array $data, array $arguments) => static::createRecord($vehicle($arguments), $data))
            ->createAnother(false);
    }

    /** @param  Vehicle|Closure(array): Vehicle  $vehicle */
    public static function reading(Vehicle|Closure $vehicle): CreateAction
    {
        $vehicle = static::resolver($vehicle);

        return CreateAction::make('addReading')
            ->label(__('Odometer'))
            ->icon('heroicon-o-map')
            ->modalHeading(__('Add odometer reading'))
            ->slideOver()
            ->model(OdometerReading::class)
            ->schema(fn (array $arguments) => OdometerReadingForm::components($vehicle($arguments)))
            ->using(fn (array $data, array $arguments) => $vehicle($arguments)->odometerReadings()->create($data))
            ->createAnother(false);
    }

    /** @return Closure(array): Vehicle */
    private static function resolver(Vehicle|Closure $vehicle): Closure
    {
        return $vehicle instanceof Closure ? $vehicle : fn () => $vehicle;
    }

    /** Creates a log entry and completes the chosen reminders. */
    public static function createRecord(Vehicle $vehicle, array $data): Record
    {
        return DB::transaction(function () use ($vehicle, $data) {
            $reminders = Arr::pull($data, 'complete_reminders') ?? [];

            /** @var Record $record */
            $record = $vehicle->records()->create($data);

            foreach ($vehicle->reminders()->whereKey($reminders)->get() as $reminder) {
                Reminders::complete($reminder, $record->date, $record->mileage, $record);
            }

            return $record;
        });
    }
}
