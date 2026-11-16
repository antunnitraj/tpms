<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\Urgency;
use App\Filament\Actions\QuickAddActions;
use App\Filament\Actions\ReminderActions;
use App\Filament\Actions\ShareVehicleAction;
use App\Filament\Resources\Vehicles\Pages\Concerns\DispatchesVehicleChanges;
use App\Filament\Resources\Vehicles\Pages\Concerns\VehiclePageHeading;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Reminder;
use App\Services\Odometer;
use App\Services\Reminders;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ViewVehicle extends ViewRecord
{
    use DispatchesVehicleChanges, VehiclePageHeading;

    protected static string $resource = VehicleResource::class;

    protected string $view = 'filament.vehicles.overview';

    public static function getNavigationLabel(): string
    {
        return __('Overview');
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected function getHeaderActions(): array
    {
        $vehicle = $this->vehicle();

        // Adding entries happens through the quick-add tiles; the header only keeps a menu.
        return [
            ActionGroup::make([
                ShareVehicleAction::make(),
                EditAction::make()->label(__('Vehicle settings'))->url(VehicleResource::getUrl('edit', ['record' => $vehicle])),
                VehicleResource::deleteAction(),
            ])
                ->label(__('More'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->size('lg')
                ->tooltip(__('More')),
        ];
    }

    public function addFuelAction(): CreateAction
    {
        return QuickAddActions::fuel($this->vehicle());
    }

    public function addRecordAction(): CreateAction
    {
        return QuickAddActions::record($this->vehicle());
    }

    public function addReadingAction(): CreateAction
    {
        return QuickAddActions::reading($this->vehicle());
    }

    public function completeReminderAction(): Action
    {
        return ReminderActions::complete('completeReminder');
    }

    /** @return Collection<int, array{reminder: Reminder, urgency: Urgency}> */
    public function dueReminders(): Collection
    {
        $vehicle = $this->vehicle();
        $mileage = Odometer::current($vehicle);
        $thresholds = auth()->user()->reminderThresholds();

        return $vehicle->reminders()->open()->get()
            ->reject(fn (Reminder $r) => Reminders::isSnoozed($r))
            ->map(fn (Reminder $r) => ['reminder' => $r, 'urgency' => Reminders::urgency($r, $mileage, $thresholds), 'mileage' => $mileage])
            ->sortBy([fn ($a, $b) => $b['urgency']->severity() <=> $a['urgency']->severity(), fn ($a, $b) => ($a['reminder']->due_date?->timestamp ?? PHP_INT_MAX) <=> ($b['reminder']->due_date?->timestamp ?? PHP_INT_MAX)])
            ->take(5)
            ->values();
    }

    /** Latest log entries and fuel-ups, newest first. */
    public function recentActivity(): Collection
    {
        $id = $this->vehicle()->getKey();

        return DB::query()->fromSub(
            DB::table('records')->selectRaw("id, 'record' as kind, category, date, mileage, description, cost, created_at")->where('vehicle_id', $id)
                ->unionAll(DB::table('fuel_records')->selectRaw("id, 'fuel' as kind, null as category, date, mileage, null as description, cost, created_at")->where('vehicle_id', $id)),
            'activity'
        )->orderByDesc('date')->orderByDesc('created_at')->limit(8)->get();
    }
}
