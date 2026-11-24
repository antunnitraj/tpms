<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\PlanStatus;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Schemas\Fields;
use App\Filament\Schemas\PlanForm;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Services\Plans;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Planned work in three lanes: planned → doing → done (which logs it). */
class ManageVehiclePlans extends VehicleEntriesPage
{
    protected static string $relationship = 'plans';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getNavigationLabel(): string
    {
        return __('Plans');
    }

    protected function getHeaderActions(): array
    {
        $vehicle = $this->vehicle();

        return [
            CreateAction::make()->label(__('Add plan'))->modalHeading(__('Add plan'))->icon('heroicon-o-plus')->labeledFrom('sm')->slideOver()
                ->model(Plan::class)
                ->schema(fn () => PlanForm::components($vehicle))
                ->using(fn (array $data) => $vehicle->plans()->create($data))
                ->createAnother(false),
        ];
    }

    public function getTabs(): array
    {
        $counts = $this->vehicle()->plans()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return collect(PlanStatus::cases())->mapWithKeys(fn (PlanStatus $status) => [
            $status->value => Tab::make($status->getLabel())
                ->icon($status->getIcon())
                ->badge($counts[$status->value] ?? null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status)),
        ])->all();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return PlanStatus::Planned->value;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(PlanForm::components($this->vehicle()))->columns(1);
    }

    public function table(Table $table): Table
    {
        $vehicle = $this->vehicle();

        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('description')->weight(FontWeight::SemiBold)->searchable()->wrap(),
                        TextColumn::make('category')->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn (Plan $record) => collect([
                                __('becomes :type', ['type' => mb_strtolower($record->category->getLabel())]),
                                $record->completed_at ? __('done :date', ['date' => $record->completed_at->toFormattedDateString()]) : null,
                                $record->reminders()->count() ? __('completes reminders') : null,
                            ])->filter()->implode(' · ')),
                        TextColumn::make('notes')->size(TextSize::Small)->color('gray')->limit(100)->visibleFrom('sm'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('priority')->badge()->alignEnd(),
                        TextColumn::make('cost')->money(fn () => AppSetting::currency())->alignEnd()->size(TextSize::Small),
                    ])->grow(false)->space(1),
                ]),
            ])
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw("case priority when 'critical' then 0 when 'normal' then 1 else 2 end")
                ->orderByDesc('updated_at'))
            // A vehicle's plans fit on one page; paging them only hid some.
            ->paginated(false)
            ->recordAction('edit')
            ->recordActions([
                Action::make('start')
                    ->label(__('Start'))
                    ->icon('heroicon-o-play')
                    ->iconButton()
                    ->color('warning')
                    ->action(fn (Plan $record) => $record->update(['status' => PlanStatus::Doing]))
                    ->visible(fn (Plan $record) => $record->status === PlanStatus::Planned),
                Action::make('finish')
                    ->label(__('Done'))
                    ->icon('heroicon-o-check')
                    ->iconButton()
                    ->color('success')
                    ->modalHeading(fn (Plan $record) => __('Finish “:plan”', ['plan' => $record->description]))
                    ->modalDescription(__('This adds the work to the log and completes linked reminders.'))
                    ->modalWidth('md')
                    ->schema(fn () => [Grid::make(['default' => 2])->schema([Fields::date(), Fields::mileage($vehicle)])])
                    ->action(function (Plan $record, array $data) use ($vehicle) {
                        Plans::complete($record, CarbonImmutable::parse($data['date']), filled($data['mileage']) ? (int) $data['mileage'] : null);
                        Notification::make()->success()->title(__('Added to the log'))
                            ->actions([Action::make('view')->label(__('Open log'))->url(VehicleResource::getUrl('log', ['record' => $vehicle]))])
                            ->send();
                    })
                    ->visible(fn (Plan $record) => $record->status === PlanStatus::Doing),
                ActionGroup::make([
                    EditAction::make()->slideOver()->visible(fn (Plan $record) => $record->status !== PlanStatus::Done),
                    Action::make('finishPlanned')
                        ->label(__('Mark done'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->modalHeading(fn (Plan $record) => __('Finish “:plan”', ['plan' => $record->description]))
                        ->modalDescription(__('This adds the work to the log and completes linked reminders.'))
                        ->modalWidth('md')
                        ->schema(fn () => [Grid::make(['default' => 2])->schema([Fields::date(), Fields::mileage($vehicle)])])
                        ->action(function (Plan $record, array $data) use ($vehicle) {
                            Plans::complete($record, CarbonImmutable::parse($data['date']), filled($data['mileage']) ? (int) $data['mileage'] : null);
                            Notification::make()->success()->title(__('Added to the log'))
                                ->actions([Action::make('view')->label(__('Open log'))->url(VehicleResource::getUrl('log', ['record' => $vehicle]))])
                                ->send();
                        })
                        ->visible(fn (Plan $record) => $record->status === PlanStatus::Planned),
                    Action::make('backToPlanned')->label(__('Move back to planned'))->icon('heroicon-o-arrow-uturn-left')
                        ->action(fn (Plan $record) => $record->update(['status' => PlanStatus::Planned]))
                        ->visible(fn (Plan $record) => $record->status === PlanStatus::Doing),
                    Action::make('openRecord')->label(__('Open log entry'))->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn () => VehicleResource::getUrl('log', ['record' => $vehicle]))
                        ->visible(fn (Plan $record) => $record->completed_record_id !== null),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading(fn () => $this->emptyHeading(__('No plans here')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Plan upcoming work; finishing a plan adds it to the log.')))
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}
