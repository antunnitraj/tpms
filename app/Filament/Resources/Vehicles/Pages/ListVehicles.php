<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Actions\QuickAddActions;
use App\Filament\Actions\ReminderActions;
use App\Filament\Pages\Admin\ImportLubeLogger;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Schemas\VehicleForm;
use App\Models\Vehicle;
use App\Services\VehicleStats;
use App\Support\Clock;
use App\Support\Format;
use App\Support\UpcomingReminders;
use App\Support\VehicleCard;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/** The garage: a card per vehicle, with anything that needs attention on top. */
class ListVehicles extends Page
{
    protected static string $resource = VehicleResource::class;

    protected string $view = 'filament.vehicles.garage';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $showSold = false;

    public function getTitle(): string
    {
        return __('Garage');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        $vehicles = $this->baseQuery()->whereNull('sold_date')->get();

        if ($vehicles->isEmpty()) {
            return null;
        }

        $year = Clock::today()->year;
        $spent = $vehicles->sum(fn (Vehicle $vehicle) => VehicleStats::totalCost($vehicle, $year));

        return trans_choice(':count vehicle|:count vehicles', $vehicles->count(), ['count' => $vehicles->count()])
            .' · '.__(':amount spent in :year', ['amount' => Format::money($spent), 'year' => $year]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add vehicle'))
                ->icon('heroicon-o-plus')->labeledFrom('sm')
                ->model(Vehicle::class)
                ->schema(VehicleForm::components())
                ->slideOver()
                ->createAnother(false)
                ->using(fn (array $data) => DB::transaction(function () use ($data) {
                    $vehicle = Vehicle::query()->create($data + ['created_by' => auth()->id()]);
                    $vehicle->users()->attach(auth()->id());

                    return $vehicle;
                }))
                ->successRedirectUrl(fn (Vehicle $record) => VehicleResource::getUrl('view', ['record' => $record])),
        ];
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label(__('Import from LubeLogger'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->url(ImportLubeLogger::getUrl())
            ->visible(fn () => ImportLubeLogger::canAccess());
    }

    public function completeReminderAction(): Action
    {
        return ReminderActions::complete('completeReminder')->size('sm');
    }

    // Quick adds on each card: the vehicle comes in as an argument.

    public function addFuelAction(): CreateAction
    {
        return QuickAddActions::fuel(fn (array $arguments) => $this->cardVehicle($arguments))
            ->modalDescription(fn (array $arguments) => $this->cardVehicle($arguments)->display_name);
    }

    public function addRecordAction(): CreateAction
    {
        return QuickAddActions::record(fn (array $arguments) => $this->cardVehicle($arguments))
            ->modalDescription(fn (array $arguments) => $this->cardVehicle($arguments)->display_name);
    }

    public function addReadingAction(): CreateAction
    {
        return QuickAddActions::reading(fn (array $arguments) => $this->cardVehicle($arguments))
            ->modalDescription(fn (array $arguments) => $this->cardVehicle($arguments)->display_name);
    }

    /** Only vehicles this user can open. */
    private function cardVehicle(array $arguments): Vehicle
    {
        return $this->baseQuery()->findOrFail($arguments['vehicle'] ?? null);
    }

    private function baseQuery(): Builder
    {
        return Vehicle::query()->accessibleBy(auth()->user());
    }

    /** @return Collection<int, array{vehicle: Vehicle, card: array}> */
    public function vehicles(): Collection
    {
        $search = trim($this->search);
        // Typed % and _ are plain characters, not wildcards.
        $like = '%'.addcslashes($search, '%_\\').'%';

        return $this->baseQuery()
            ->with('media')
            ->when(! $this->showSold, fn (Builder $query) => $query->whereNull('sold_date'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->whereRaw('make ilike ?', [$like])
                ->orWhereRaw('model ilike ?', [$like])
                ->orWhereRaw('nickname ilike ?', [$like])
                ->orWhereRaw('license_plate ilike ?', [$like])))
            ->orderByRaw('sold_date is not null')
            ->orderBy('make')
            ->orderBy('model')
            ->get()
            ->map(fn (Vehicle $vehicle) => ['vehicle' => $vehicle, 'card' => VehicleCard::for($vehicle, auth()->user())]);
    }

    public function totalVehicles(): int
    {
        return $this->baseQuery()->count();
    }

    public function soldVehicles(): int
    {
        return $this->baseQuery()->whereNotNull('sold_date')->count();
    }

    public function attention(): Collection
    {
        return UpcomingReminders::for(auth()->user());
    }
}
