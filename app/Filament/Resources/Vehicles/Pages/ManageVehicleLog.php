<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\RecordCategory;
use App\Filament\Actions\QuickAddActions;
use App\Filament\Schemas\Fields;
use App\Filament\Schemas\RecordForm;
use App\Models\AppSetting;
use App\Models\Record;
use App\Services\VehicleStats;
use App\Support\Clock;
use App\Support\Format;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ManageVehicleLog extends VehicleEntriesPage
{
    protected static string $relationship = 'records';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    public static function getNavigationLabel(): string
    {
        return __('Log');
    }

    protected function getHeaderActions(): array
    {
        $category = in_array($this->activeTab, array_column(auth()->user()->recordCategories(), 'value'), true) ? $this->activeTab : null;

        return [QuickAddActions::record($this->vehicle(), $category)->label(__('Add entry'))->icon('heroicon-o-plus')->labeledFrom('sm')];
    }

    public function getTabs(): array
    {
        $counts = $this->vehicle()->records()->selectRaw('category, count(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category');

        return [
            'all' => Tab::make(__('All'))->badge($counts->sum() ?: null),
            // "All" still lists every entry; tabs only for the types this user works with.
            ...collect(auth()->user()->recordCategories())->mapWithKeys(fn (RecordCategory $category) => [
                $category->value => Tab::make($category->getLabel())
                    ->icon($category->getIcon())
                    ->badge($counts[$category->value] ?? null)
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('category', $category)),
            ])->all(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(RecordForm::components($this->vehicle(), creating: false))->columns(1);
    }

    public function table(Table $table): Table
    {
        $unit = $this->vehicle()->distanceUnit();

        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('media'))
            ->columns([
                Split::make([
                    IconColumn::make('category')->grow(false)
                        ->icon(fn (RecordCategory $state) => $state->getIcon())
                        ->color(fn (RecordCategory $state) => $state->getColor()),
                    Stack::make([
                        TextColumn::make('description')->weight(FontWeight::SemiBold)->searchable()->wrap(),
                        TextColumn::make('date')->sortable()->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn (Record $record) => collect([
                                $record->date->toFormattedDateString(),
                                $record->mileage ? Format::distance($record->mileage, $unit) : null,
                                $record->getMedia('attachments')->count() ? trans_choice(':count file|:count files', $record->getMedia('attachments')->count()) : null,
                            ])->filter()->implode(' · ')),
                        TextColumn::make('notes')->size(TextSize::Small)->color('gray')->limit(80)->searchable()->visibleFrom('md'),
                    ])->space(1),
                    TextColumn::make('cost')->money(fn () => AppSetting::currency())->weight(FontWeight::SemiBold)
                        ->alignEnd()->grow(false)->sortable(),
                ]),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('year')
                    ->label(__('Year'))
                    ->options(fn () => collect(VehicleStats::years($this->vehicle()))->mapWithKeys(fn ($y) => [$y => $y]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $year) => $q->whereYear('date', $year))),
                SelectFilter::make('tag')
                    ->label(__('Tag'))
                    ->options(fn () => collect(Fields::existingTags($this->vehicle(), 'records'))->mapWithKeys(fn ($t) => [$t => $t]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn ($q, $tag) => $q->whereJsonContains('tags', $tag))),
            ])
            ->recordAction('edit')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->slideOver(),
                    // The copy is today's: the original's odometer would put a stale reading in the timeline.
                    ReplicateAction::make()->label(__('Duplicate'))
                        ->modalHeading(__('Duplicate this entry?'))
                        ->modalDescription(__('The copy is dated today, without an odometer reading.'))
                        ->modalSubmitActionLabel(__('Duplicate'))
                        ->successNotificationTitle(__('Duplicated'))
                        ->excludeAttributes(['import_id', 'source_id', 'mileage'])
                        ->beforeReplicaSaved(fn (Record $replica) => $replica->date = Clock::today()),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('changeCategory')
                        ->label(__('Change type'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->schema([ToggleButtons::make('category')->label(__('Type'))->options(RecordCategory::class)->inline()->required()])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['category' => $data['category']]))
                        ->modalSubmitActionLabel(__('Change type'))
                        ->successNotificationTitle(__('Type changed'))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn () => $this->emptyHeading(__('Nothing logged yet')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Services, repairs, upgrades and fees show up here.')))
            ->emptyStateIcon('heroicon-o-wrench-screwdriver');
    }
}
