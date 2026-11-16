<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\Urgency;
use App\Filament\Actions\ReminderActions;
use App\Filament\Schemas\ReminderForm;
use App\Models\Reminder;
use App\Services\Odometer;
use App\Services\Reminders;
use App\Support\Clock;
use App\Support\ReminderText;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageVehicleReminders extends VehicleEntriesPage
{
    protected static string $relationship = 'reminders';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected ?int $currentMileage = null;

    public static function getNavigationLabel(): string
    {
        return __('Reminders');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add reminder'))->modalHeading(__('Add reminder'))->icon('heroicon-o-plus')->labeledFrom('sm')->slideOver()
                ->model(Reminder::class)
                ->schema(fn () => ReminderForm::components($this->vehicle()))
                ->using(fn (array $data) => $this->vehicle()->reminders()->create($data))
                ->createAnother(false),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))->modifyQueryUsing(fn (Builder $query) => $query->whereNull('completed_at')),
            'done' => Tab::make(__('Done'))->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('completed_at')),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(ReminderForm::components($this->vehicle()))->columns(1);
    }

    /**
     * The same levels as the badges (see Reminders::urgency): past due, very urgent, urgent,
     * by date or by distance, whichever is worse; snoozed ones wait. Then by due date and mileage.
     */
    private function mostPressingFirst(Builder $query, ?int $mileage, array $thresholds): Builder
    {
        $today = Clock::today()->toDateString();
        $veryDays = max(1, (int) $thresholds['very_urgent_days']);
        $urgentDays = (int) $thresholds['urgent_days'];
        $byDate = "case when due_date is null then 0 when due_date < ?::date then 3 when due_date < ?::date + {$veryDays} then 2 when due_date < ?::date + {$urgentDays} then 1 else 0 end";
        $byDistance = $mileage === null ? '0' : sprintf(
            'case when due_mileage is null then 0 when due_mileage < %1$d then 3 when due_mileage - %1$d < %2$d then 2 when due_mileage - %1$d < %3$d then 1 else 0 end',
            $mileage, (int) $thresholds['very_urgent_distance'], (int) $thresholds['urgent_distance'],
        );

        return $query
            ->orderByRaw("case when snoozed_until > ?::date then 0 else greatest({$byDate}, {$byDistance}) end desc", [$today, $today, $today, $today])
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('due_mileage');
    }

    public function table(Table $table): Table
    {
        $thresholds = auth()->user()->reminderThresholds();
        $mileage = fn () => $this->currentMileage ??= Odometer::current($this->vehicle());

        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('description')->weight(FontWeight::SemiBold)->searchable()->wrap(),
                        TextColumn::make('due_date')->size(TextSize::Small)->color('gray')
                            ->state(fn (Reminder $record) => $record->isCompleted()
                                ? __('Done :date', ['date' => $record->last_completed_on?->toFormattedDateString()])
                                : ReminderText::due($record, $mileage()).(Reminders::isSnoozed($record) ? ' · '.__('snoozed until :date', ['date' => $record->snoozed_until->toFormattedDateString()]) : '')),
                        TextColumn::make('repeat')->size(TextSize::Small)->color('gray')
                            ->state(fn (Reminder $record) => $record->describeRepeat()),
                    ])->space(1),
                    // Only flag what needs attention; "not urgent" would just be noise.
                    TextColumn::make('urgency')->badge()->alignEnd()->grow(false)
                        ->state(function (Reminder $record) use ($mileage, $thresholds) {
                            // Snoozed: the text says until when, a red badge would say the opposite.
                            if (Reminders::isSnoozed($record)) {
                                return null;
                            }

                            $urgency = Reminders::urgency($record, $mileage(), $thresholds);

                            return $urgency->severity() >= Urgency::Urgent->severity() ? $urgency : null;
                        })
                        ->formatStateUsing(fn ($state) => $state?->getLabel())
                        ->color(fn ($state) => $state?->getColor())
                        ->icon(fn ($state) => $state?->getIcon()),
                ]),
            ])
            ->defaultSort(fn (Builder $query) => $this->mostPressingFirst($query, $mileage(), $thresholds))
            ->recordAction('edit')
            ->recordActions([
                ReminderActions::complete()->iconButton(),
                ActionGroup::make([
                    EditAction::make()->slideOver(),
                    ReminderActions::snooze(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading(fn () => $this->emptyHeading(__('No reminders')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Get reminded by date, mileage or both — whichever comes first.')))
            ->emptyStateIcon('heroicon-o-bell');
    }
}
