<?php

use App\Enums\PlanPriority;
use App\Enums\PlanStatus;
use App\Enums\RecordCategory;
use App\Enums\RepeatUnit;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleEquipment;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleFuel;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleLog;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleNotes;
use App\Filament\Resources\Vehicles\Pages\ManageVehiclePlans;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleReminders;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleSupplies;
use App\Filament\Resources\Vehicles\Pages\VehicleOdometer;
use App\Filament\Resources\Vehicles\Pages\VehicleReports;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Models\Equipment;
use App\Models\FuelRecord;
use App\Models\Note;
use App\Models\Plan;
use App\Models\Record;
use App\Models\Reminder;
use App\Models\Supply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->vehicle = vehicleFor($this->user);
});

it('adds a fuel-up from the overview', function () {
    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('addFuel', data: ['date' => '2026-01-05', 'mileage' => 12_000, 'quantity' => 40, 'cost' => 62.4, 'is_full' => true, 'missed_previous' => false])
        ->assertHasNoActionErrors()
        // The stats on the page refresh straight away (they don't poll).
        ->assertDispatched('vehicle-changed');

    expect($this->vehicle->fuelRecords()->sole())->mileage->toBe(12_000)->and((float) $this->vehicle->fuelRecords()->sole()->cost)->toBe(62.4);
});

it('leaves the fuel total empty until it is entered', function () {
    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->mountAction('addFuel')
        ->assertActionDataSet(['cost' => null]);

    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('addFuel', data: ['date' => '2026-01-05', 'mileage' => 12_000, 'quantity' => 40])
        ->assertHasActionErrors(['cost' => 'required']);

    expect($this->vehicle->fuelRecords()->count())->toBe(0);
});

it('adds a log entry that completes a recurring reminder', function () {
    $reminder = Reminder::factory()->for($this->vehicle)->create([
        'description' => 'Oil change', 'due_date' => '2026-02-01', 'due_mileage' => 15_000,
        'repeat_every' => 1, 'repeat_unit' => RepeatUnit::Year, 'repeat_distance' => 10_000,
    ]);

    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('addRecord', data: [
            'category' => RecordCategory::Service->value, 'description' => 'Oil change', 'date' => '2026-01-10',
            'mileage' => 14_500, 'cost' => 80, 'complete_reminders' => [$reminder->id],
        ])
        ->assertHasNoActionErrors();

    $record = $this->vehicle->records()->sole();
    expect($reminder->fresh())
        ->due_date->toDateString()->toBe('2027-01-10')
        ->due_mileage->toBe(24_500)
        ->and($reminder->records()->pluck('records.id')->all())->toBe([$record->id]);
});

it('marks a reminder done and logs it in one step', function () {
    $reminder = Reminder::factory()->for($this->vehicle)->create(['description' => 'Registration', 'due_date' => '2026-01-01']);

    Livewire::test(ManageVehicleReminders::class, ['record' => $this->vehicle->getRouteKey()])
        // With an odometer reading filled in: a tax or fee still isn't tied to the odometer.
        ->callAction(TestAction::make('complete')->table($reminder), data: [
            'date' => '2026-01-02', 'mileage' => 15_000, 'log' => true, 'category' => RecordCategory::TaxFee->value, 'cost' => 120,
        ])
        ->assertHasNoActionErrors();

    expect($reminder->fresh()->isCompleted())->toBeTrue()
        ->and($this->vehicle->records()->sole())
        ->category->toBe(RecordCategory::TaxFee)
        ->description->toBe('Registration')
        ->mileage->toBeNull();
});

it('opens the edit form on every section page', function (string $page, Closure $make) {
    $entry = $make($this->vehicle);

    Livewire::test($page, ['record' => $this->vehicle->getRouteKey()])
        ->mountAction(TestAction::make('edit')->table($entry))
        ->assertHasNoErrors()
        ->callMountedAction()
        ->assertHasNoActionErrors();
})->with([
    'log' => [ManageVehicleLog::class, fn ($vehicle) => Record::factory()->for($vehicle)->create(['tags' => ['a']])],
    'fuel' => [ManageVehicleFuel::class, fn ($vehicle) => FuelRecord::factory()->for($vehicle)->create(['tags' => ['a']])],
    'reminders' => [ManageVehicleReminders::class, fn ($vehicle) => Reminder::factory()->for($vehicle)->create(['tags' => ['a']])],
    'plans' => [ManageVehiclePlans::class, fn ($vehicle) => Plan::factory()->for($vehicle)->create(['tags' => ['a']])],
    'notes' => [ManageVehicleNotes::class, fn ($vehicle) => Note::query()->create(['vehicle_id' => $vehicle->id, 'title' => 'Tyres', 'body' => 'Summer set', 'tags' => ['a']])],
    'equipment' => [ManageVehicleEquipment::class, fn ($vehicle) => Equipment::query()->create(['vehicle_id' => $vehicle->id, 'name' => 'Winter tyres', 'tags' => ['a']])],
    'supplies' => [ManageVehicleSupplies::class, fn ($vehicle) => Supply::factory()->create(['vehicle_id' => $vehicle->id, 'user_id' => $vehicle->created_by ?? auth()->id(), 'tags' => ['a']])],
]);

it('edits a plan that completes reminders', function () {
    $reminder = Reminder::factory()->for($this->vehicle)->create(['description' => 'Brake fluid', 'tags' => ['brakes']]);
    $plan = Plan::factory()->for($this->vehicle)->create(['description' => 'Brakes', 'tags' => ['brakes']]);
    $plan->reminders()->attach($reminder);

    Livewire::test(ManageVehiclePlans::class, ['record' => $this->vehicle->getRouteKey()])
        ->mountAction(TestAction::make('edit')->table($plan))
        ->assertSchemaStateSet(['description' => 'Brakes', 'reminders' => [$reminder->id]])
        ->fillForm(['description' => 'Brake pads and fluid'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($plan->fresh()->description)->toBe('Brake pads and fluid');
});

it('shows every plan\'s priority', function () {
    foreach (PlanPriority::cases() as $priority) {
        Plan::factory()->for($this->vehicle)->create(['description' => "Job {$priority->value}", 'priority' => $priority]);
    }

    Livewire::test(ManageVehiclePlans::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSeeInOrder(['Job critical', 'Critical', 'Job normal', 'Normal', 'Job low', 'Low']);
});

it('finishes a plan into the log', function () {
    $plan = Plan::factory()->for($this->vehicle)->create(['description' => 'New wipers', 'status' => PlanStatus::Doing]);

    Livewire::test(ManageVehiclePlans::class, ['record' => $this->vehicle->getRouteKey()])
        ->set('activeTab', PlanStatus::Doing->value)
        ->callAction(TestAction::make('finish')->table($plan), data: ['date' => '2026-01-03', 'mileage' => 13_000])
        ->assertHasNoActionErrors();

    expect($plan->fresh()->status)->toBe(PlanStatus::Done)
        ->and($this->vehicle->records()->sole()->description)->toBe('New wipers');
});

it('shares a vehicle by username and lets collaborators remove others', function () {
    $friend = User::factory()->create(['username' => 'friend']);

    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('share', data: ['collaborators' => [], 'add' => ['friend', 'nobody']])
        ->assertHasNoActionErrors();

    expect($this->vehicle->users()->pluck('username')->sort()->values()->all())->toBe(collect([$this->user->username, 'friend'])->sort()->values()->all());

    // The friend has full access, including removing the original owner.
    $this->actingAs($friend);
    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('share', data: ['collaborators' => [], 'add' => []])
        ->assertHasNoActionErrors();

    expect($this->vehicle->users()->pluck('username')->all())->toBe(['friend']);
});

it('lists due reminders on the garage and completes them from there', function () {
    $reminder = Reminder::factory()->for($this->vehicle)->create(['description' => 'Registration renewal', 'due_date' => now()->addDay()->toDateString()]);
    Reminder::factory()->for($this->vehicle)->create(['description' => 'Far away', 'due_date' => now()->addYear()->toDateString()]);

    Livewire::test(ListVehicles::class)
        ->assertSee('Needs attention')
        ->assertSee('Registration renewal')
        ->assertDontSee('Far away')
        ->assertSee($this->vehicle->display_name)
        ->callAction('completeReminder', data: ['date' => now()->toDateString(), 'mileage' => null, 'log' => false], arguments: ['reminder' => $reminder->id])
        ->assertHasNoActionErrors()
        ->assertDontSee('Needs attention');

    expect($reminder->fresh()->isCompleted())->toBeTrue();
});

it('hides sold vehicles from the garage unless asked', function () {
    vehicleFor($this->user, ['make' => 'Lada', 'model' => 'Niva', 'sold_date' => '2025-01-01']);

    Livewire::test(ListVehicles::class)
        ->assertDontSee('Niva')
        ->assertSee('Show 1 sold vehicle')
        ->set('showSold', true)
        ->assertSee('Niva');
});

it('lets reports leave out cost categories and remembers the choice', function () {
    Record::factory()->for($this->vehicle)->create(['date' => '2026-01-10', 'cost' => 100, 'category' => RecordCategory::Service, 'description' => 'Oil change']);
    Record::factory()->for($this->vehicle)->create(['date' => '2026-02-10', 'cost' => 300, 'category' => RecordCategory::Repair, 'description' => 'Bumper']);
    FuelRecord::factory()->for($this->vehicle)->create(['date' => '2026-03-01', 'cost' => 60]);

    $page = Livewire::test(VehicleReports::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSet('categories', ['service', 'repair', 'upgrade', 'tax_fee', 'fuel'])
        ->set('categories', ['fuel', 'service']);

    expect($page->get('categories'))->toBe(['service', 'fuel'])
        ->and($page->instance()->breakdown()['total']['total'])->toBe(160.0)
        ->and($this->user->fresh()->settings['report_categories'])->toBe(['service', 'fuel']);

    // The printed report follows the same selection.
    Livewire::test(VehicleReports::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSet('categories', ['service', 'fuel'])
        ->assertSee('Oil change')->assertDontSee('Bumper')
        ->set('categories', ['fuel'])
        ->assertDontSee('Oil change');
});

it('opens reports on this year, with all time one choice away', function () {
    Record::factory()->for($this->vehicle)->create(['date' => now()->subYears(2)->toDateString(), 'cost' => 500, 'category' => RecordCategory::Repair]);
    Record::factory()->for($this->vehicle)->create(['date' => now()->toDateString(), 'cost' => 40, 'category' => RecordCategory::Service]);

    $page = Livewire::test(VehicleReports::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSet('year', (string) now()->year);
    expect($page->instance()->breakdown()['total']['total'])->toBe(40.0)
        ->and($page->instance()->years())->toContain(now()->year, now()->subYears(2)->year);

    $page->set('year', 'all');
    expect($page->instance()->selectedYear())->toBeNull()
        ->and($page->instance()->breakdown()['total']['total'])->toBe(540.0);

    Livewire::withQueryParams(['year' => 'all'])->test(VehicleReports::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSet('year', 'all');
});

it('lists the most pressing reminders first, whether due by date or by distance', function () {
    FuelRecord::factory()->for($this->vehicle)->create(['mileage' => 10_000, 'date' => now()->subDay()->toDateString()]);
    $later = Reminder::factory()->for($this->vehicle)->create(['description' => 'Timing belt', 'due_date' => now()->addDays(200)->toDateString()]);
    $overdue = Reminder::factory()->for($this->vehicle)->create(['description' => 'Air filter', 'due_date' => null, 'due_mileage' => 9_700]);
    $soon = Reminder::factory()->for($this->vehicle)->create(['description' => 'Tyre rotation', 'due_date' => null, 'due_mileage' => 10_200]);
    $snoozed = Reminder::factory()->for($this->vehicle)->create(['description' => 'Wipers', 'due_date' => now()->subDays(3)->toDateString(), 'snoozed_until' => now()->addWeek()->toDateString()]);

    Livewire::test(ManageVehicleReminders::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertCanSeeTableRecords([$overdue, $soon, $snoozed, $later], inOrder: true);
});

it('shows an edited or deleted odometer reading straight away', function () {
    $reading = $this->vehicle->odometerReadings()->create(['date' => '2026-01-10', 'mileage' => 77_777]);
    $key = "odometer_reading-{$reading->id}";

    $page = Livewire::test(VehicleOdometer::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSee('77,777')
        ->callAction(TestAction::make('editReading')->table($key), data: ['date' => '2026-01-10', 'mileage' => 77_888])
        ->assertHasNoActionErrors()
        ->assertNotified('Saved')
        ->assertSee('77,888')
        ->assertDontSee('77,777');

    $page->callAction(TestAction::make('deleteReading')->table($key))
        ->assertNotified('Deleted')
        ->assertDontSee('77,888');

    expect($this->vehicle->odometerReadings()->count())->toBe(0);
});
