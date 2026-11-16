<?php

namespace App\Filament\Schemas;

use App\Enums\DistanceUnit;
use App\Enums\FuelType;
use App\Enums\FuelUnit;
use App\Support\Clock;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;

class VehicleForm
{
    /**
     * @param  bool  $settingsPage  the full-width Vehicle settings page: each section with its title and explanation
     *                              beside the fields on desktop. The garage's "Add vehicle" slide-over stays compact.
     */
    public static function components(bool $withPhotos = true, bool $settingsPage = false): array
    {
        $section = fn (string $heading, ?string $description = null) => Section::make($heading)
            ->description($description)
            ->aside($settingsPage)
            ->compact(! $settingsPage);

        return [
            $section(__('Vehicle'), $settingsPage ? __('What it is and how it shows up in your garage.') : null)
                ->columns(['sm' => 2])
                ->schema([
                    TextInput::make('make')->label(__('Make'))->required()->maxLength(100)->placeholder('Toyota'),
                    TextInput::make('model')->label(__('Model'))->required()->maxLength(100)->placeholder('Corolla'),
                    TextInput::make('year')->label(__('Year'))->integer()->inputMode('numeric')->minValue(1886)->maxValue(now()->year + 2),
                    TextInput::make('license_plate')->label(__('License plate'))->maxLength(30),
                    TextInput::make('nickname')->label(__('Nickname'))->maxLength(100)->helperText(__('Shown instead of year, make and model.'))
                        ->columnSpanFull(),
                    ToggleButtons::make('fuel_type')->label(__('Fuel'))->options(FuelType::class)->inline()->required()
                        ->default(FuelType::Gasoline)->live()
                        // Only switching to or from electric changes the unit (gallons stay gallons for diesel).
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $electric = static::fuelType($state) === FuelType::Electric;
                            $unit = $get('fuel_unit') instanceof FuelUnit ? $get('fuel_unit') : FuelUnit::tryFrom((string) $get('fuel_unit'));

                            if ($electric) {
                                $set('fuel_unit', FuelUnit::KilowattHours->value);
                            } elseif ($unit === null || $unit === FuelUnit::KilowattHours) {
                                $set('fuel_unit', FuelUnit::Liters->value);
                            }
                        })
                        ->columnSpanFull(),
                ]),
            $section(__('Units'), $settingsPage
                ? __('Entries are stored in these units; fuel economy can be shown in any format. Changing a unit doesn\'t convert entries already saved: 100 km becomes 100 mi.')
                : __('Entries are stored in these units; fuel economy can be shown in any format.'))
                ->columns(['sm' => 2])
                ->schema([
                    Select::make('distance_unit')->label(__('Distance'))->options(DistanceUnit::class)->required()->default(DistanceUnit::Kilometers)
                        ->native(false)->selectablePlaceholder(false),
                    Select::make('fuel_unit')->label(__('Fuel'))->required()->default(FuelUnit::Liters)
                        ->native(false)->selectablePlaceholder(false)
                        // Electric is charged in kWh; petrol and diesel are never measured in kWh.
                        ->options(fn (Get $get) => collect(FuelUnit::cases())
                            ->filter(fn (FuelUnit $unit) => match (static::fuelType($get('fuel_type'))) {
                                FuelType::Electric => $unit === FuelUnit::KilowattHours,
                                FuelType::Gasoline, FuelType::Diesel => $unit !== FuelUnit::KilowattHours,
                                default => true,
                            })
                            ->mapWithKeys(fn (FuelUnit $unit) => [$unit->value => $unit->getLabel()])
                            ->all()),
                ]),
            // Settings only show the photos: they are added and removed one by one on the Photos & files tab,
            // so saving this form can never drop any. A new vehicle (garage) can get its first photos here.
            ...($settingsPage ? [
                $section(__('Photos'), __('The first photo is the cover on the garage card.'))
                    ->schema([View::make('filament.vehicles.settings-photos')]),
            ] : []),
            ...($withPhotos && ! $settingsPage ? [
                $section(__('Photos'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('photos')
                            ->hiddenLabel()
                            ->collection('photos')
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->image()
                            ->imageEditor()
                            ->panelLayout('grid')
                            ->visibility('private')
                            ->maxSize(30 * 1024)
                            ->helperText(__('The first photo is the cover.')),
                    ]),
            ] : []),
            $section(__('Ownership'), $settingsPage ? __('Used for cost per day and, once sold, depreciation.') : null)
                ->columns(['sm' => 2])
                ->collapsible(! $settingsPage)
                ->schema([
                    DatePicker::make('purchase_date')->label(__('Purchase date'))->minDate(Fields::EARLIEST_DATE)->maxDate(fn () => Clock::today()->toDateString()),
                    Fields::money('purchase_price', __('Purchase price'))->minValue(0),
                    DatePicker::make('sold_date')->label(__('Sold on'))->minDate(Fields::EARLIEST_DATE)->maxDate(fn () => Clock::today()->toDateString())
                        ->afterOrEqual('purchase_date')->live(),
                    Fields::money('sold_price', __('Sold for'))->minValue(0)->visible(fn (Get $get) => filled($get('sold_date'))),
                ]),
            ...($settingsPage ? [
                $section(__('Notes & tags'), __('Anything worth remembering about this vehicle.'))
                    ->schema([
                        TagsInput::make('tags')->label(__('Tags')),
                        Textarea::make('notes')->label(__('Notes'))->rows(3)->autosize(),
                    ]),
            ] : [
                TagsInput::make('tags')->label(__('Tags')),
                Textarea::make('notes')->label(__('Notes'))->rows(2)->autosize(),
            ]),
        ];
    }

    private static function fuelType(mixed $state): ?FuelType
    {
        return $state instanceof FuelType ? $state : FuelType::tryFrom((string) $state);
    }
}
