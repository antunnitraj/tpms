<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Actions\SupplyActions;
use App\Filament\Schemas\SupplyForm;
use App\Models\Supply;
use App\Services\Supplies;
use App\Support\Clock;
use App\Support\Format;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ManageVehicleSupplies extends VehicleEntriesPage
{
    protected static string $relationship = 'supplies';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    public static function getNavigationLabel(): string
    {
        return __('Supplies');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add supply'))->modalHeading(__('Add supply'))->icon('heroicon-o-plus')->labeledFrom('sm')->slideOver()
                ->model(Supply::class)
                ->schema(fn () => SupplyForm::components(creating: true))
                ->using(fn (array $data) => static::createSupply($data, $this->vehicle()->getKey()))
                ->createAnother(false),
        ];
    }

    public static function createSupply(array $data, ?int $vehicleId): Supply
    {
        return DB::transaction(function () use ($data, $vehicleId) {
            $quantity = (float) Arr::pull($data, 'initial_quantity');
            $cost = (float) Arr::pull($data, 'initial_cost');

            $supply = Supply::query()->create($data + ['user_id' => auth()->id(), 'vehicle_id' => $vehicleId]);

            if ($quantity > 0 || $cost > 0) {
                Supplies::purchase($supply, $quantity, $cost, Clock::today());
            }

            return $supply;
        });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(SupplyForm::components(creating: false))->columns(1);
    }

    public static function supplyTable(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn (Builder $query) => $query->withStock())
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('description')->weight(FontWeight::SemiBold)->searchable()->wrap(),
                        TextColumn::make('part_number')->size(TextSize::Small)->color('gray')->searchable()
                            ->formatStateUsing(fn (Supply $record) => collect([$record->part_number, $record->supplier])->filter()->implode(' · ')),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('stock')->alignEnd()->weight(FontWeight::SemiBold)
                            ->formatStateUsing(fn ($state) => __(':count in stock', ['count' => (float) $state]))
                            ->color(fn ($state) => (float) $state > 0 ? null : 'danger'),
                        TextColumn::make('value')->alignEnd()->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn ($state) => Format::money($state)),
                    ])->grow(false),
                ]),
            ])
            ->defaultSort('description')
            ->filters([
                TernaryFilter::make('in_stock')
                    ->label(__('Stock'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('In stock'))
                    ->falseLabel(__('Used up'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereRaw('(select coalesce(sum(quantity), 0) from supply_movements where supply_movements.supply_id = supplies.id) > 0'),
                        false: fn (Builder $query) => $query->whereRaw('(select coalesce(sum(quantity), 0) from supply_movements where supply_movements.supply_id = supplies.id) <= 0'),
                    ),
            ])
            ->recordAction('edit')
            ->recordActions([
                SupplyActions::purchase()->iconButton(),
                ActionGroup::make([
                    SupplyActions::history(),
                    SupplyActions::adjust(),
                    EditAction::make()->slideOver(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-archive-box');
    }

    public function table(Table $table): Table
    {
        return static::supplyTable($table)
            ->emptyStateHeading(fn () => $this->emptyHeading(__('No supplies')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Track oil, filters and parts; using them in a log entry takes them out of stock.')));
    }
}
