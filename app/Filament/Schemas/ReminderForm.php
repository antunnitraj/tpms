<?php

namespace App\Filament\Schemas;

use App\Enums\RepeatUnit;
use App\Models\Vehicle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class ReminderForm
{
    public static function components(Vehicle $vehicle): array
    {
        $unit = $vehicle->distanceUnit()->short();

        return [
            TextInput::make('description')->label(__('What'))->required()->maxLength(255)->placeholder(__('Oil change')),
            Section::make(__('Due'))
                ->description(__('Set a date, a mileage or both — whichever comes first.'))
                ->compact()
                ->columns(['default' => 2])
                ->schema([
                    DatePicker::make('due_date')->label(__('On'))->minDate(Fields::EARLIEST_DATE)->maxDate('2999-12-31')->requiredWithout('due_mileage')->validationMessages(['required_without' => __('Set a date, an odometer reading, or both.')]),
                    Fields::mileage($vehicle, 'due_mileage')->label(__('At'))->default(null)->helperText(null)->requiredWithout('due_date')->validationMessages(['required_without' => __('Set a date, an odometer reading, or both.')]),
                ]),
            Section::make(__('Repeat'))
                ->compact()
                ->columns(['default' => 2])
                ->schema([
                    TextInput::make('repeat_every')->label(__('Every'))->integer()->minValue(1)->maxValue(1000)->inputMode('numeric')->live(onBlur: true),
                    Select::make('repeat_unit')->label(__('Unit'))->options(RepeatUnit::class)->default(RepeatUnit::Month)
                        ->required(fn (Get $get) => filled($get('repeat_every')))->native(false),
                    TextInput::make('repeat_distance')->label(__('…or every'))->integer()->minValue(1)->maxValue(10_000_000)->inputMode('numeric')->suffix($unit)
                        ->columnSpanFull(),
                    Toggle::make('fixed_schedule')->label(__('Keep a fixed schedule'))
                        ->helperText(__('Next due date counts from the previous due date instead of when it was done (good for insurance or registration).'))
                        ->columnSpanFull(),
                ]),
            Fields::notes(),
            Fields::tags($vehicle, 'reminders'),
        ];
    }
}
