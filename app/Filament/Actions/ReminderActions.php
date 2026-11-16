<?php

namespace App\Filament\Actions;

use App\Enums\RecordCategory;
use App\Filament\Schemas\Fields;
use App\Models\Reminder;
use App\Services\Reminders;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;

/**
 * Reminder actions usable as table row actions (the reminder is the row) or
 * page actions (the reminder id is passed as the "reminder" argument).
 */
class ReminderActions
{
    public static function complete(string $name = 'complete'): Action
    {
        return Action::make($name)
            ->label(__('Done'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->modalHeading(fn ($record, array $arguments) => __('Mark “:reminder” done', ['reminder' => static::resolve($record, $arguments)?->description]))
            ->modalSubmitActionLabel(__('Mark done'))
            ->modalWidth('lg')
            ->schema(function ($record, array $arguments) {
                $reminder = static::resolve($record, $arguments);
                $vehicle = $reminder->vehicle;

                return [
                    Grid::make(['default' => 2])->schema([
                        Fields::date(),
                        Fields::mileage($vehicle),
                    ]),
                    Toggle::make('log')->label(__('Add it to the log'))->default(true)->live(),
                    ToggleButtons::make('category')->label(__('Type'))->options(RecordCategory::class)->inline()
                        ->default(RecordCategory::Service)->visible(fn (Get $get) => $get('log')),
                    Fields::money('cost')->default(0)->visible(fn (Get $get) => $get('log')),
                    Fields::notes()->default($reminder->notes)->visible(fn (Get $get) => $get('log')),
                ];
            })
            ->action(function ($record, array $arguments, array $data) {
                $reminder = static::resolve($record, $arguments);
                $date = CarbonImmutable::parse($data['date']);
                $mileage = filled($data['mileage'] ?? null) ? (int) $data['mileage'] : null;

                DB::transaction(function () use ($reminder, $data, $date, $mileage) {
                    $entry = null;
                    if ($data['log'] ?? false) {
                        // The form gives the enum; taxes and fees aren't tied to the odometer.
                        $category = $data['category'] instanceof RecordCategory ? $data['category'] : RecordCategory::from($data['category']);
                        $entry = $reminder->vehicle->records()->create([
                            'category' => $category,
                            'date' => $date->toDateString(),
                            'mileage' => $category === RecordCategory::TaxFee ? null : $mileage,
                            'description' => $reminder->description,
                            'cost' => $data['cost'] ?: 0,
                            'notes' => $data['notes'] ?? null,
                            'tags' => $reminder->tags,
                        ]);
                    }

                    Reminders::complete($reminder, $date, $mileage, $entry);
                });

                Notification::make()->success()
                    ->title($reminder->fresh()->isCompleted() ? __('Reminder done') : __('Done — next one is scheduled'))
                    ->send();
            })
            ->visible(fn ($record, array $arguments) => ! static::resolve($record, $arguments)?->isCompleted());
    }

    public static function snooze(string $name = 'snooze'): Action
    {
        return Action::make($name)
            ->label(__('Snooze'))
            ->icon('heroicon-o-moon')
            ->color('gray')
            ->modalWidth('sm')
            ->modalSubmitActionLabel(__('Snooze'))
            ->schema([
                DatePicker::make('until')->label(__('Remind me again on'))->required()
                    ->default(fn () => Clock::today()->addWeek()->toDateString())
                    ->minDate(fn () => Clock::today()->addDay()->toDateString()),
            ])
            ->action(function ($record, array $arguments, array $data) {
                Reminders::snooze(static::resolve($record, $arguments), CarbonImmutable::parse($data['until']));
                Notification::make()->success()->title(__('Snoozed'))->send();
            })
            ->visible(fn ($record, array $arguments) => ! static::resolve($record, $arguments)?->isCompleted());
    }

    public static function resolve(mixed $record, array $arguments): ?Reminder
    {
        if ($record instanceof Reminder) {
            return $record;
        }

        $id = $arguments['reminder'] ?? (is_array($record) ? ($record['id'] ?? null) : null);

        return $id ? Reminder::query()->accessibleBy(auth()->user())->find($id) : null;
    }
}
