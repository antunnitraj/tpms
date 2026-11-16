<?php

use App\Enums\RecordCategory;
use App\Enums\VehicleTab;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleLog;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->vehicle = vehicleFor($this->user);
});

it('hides the vehicle tabs a user switches off', function () {
    Livewire::test(Settings::class)
        ->fillForm(['vehicle_tabs' => ['log', 'reminders', 'reports'], 'record_categories' => ['service', 'repair']])
        ->call('save')
        ->assertHasNoFormErrors();

    $user = $this->user->fresh();
    expect($user->showsVehicleTab(VehicleTab::Fuel))->toBeFalse()
        ->and($user->showsVehicleTab(VehicleTab::Log))->toBeTrue()
        ->and($user->recordCategories())->toBe([RecordCategory::Service, RecordCategory::Repair]);

    $this->actingAs($user)->get(VehicleResource::getUrl('view', ['record' => $this->vehicle]))->assertOk()
        ->assertSee(VehicleResource::getUrl('log', ['record' => $this->vehicle]), escape: false)
        ->assertDontSee(VehicleResource::getUrl('fuel', ['record' => $this->vehicle]), escape: false)
        ->assertSee(VehicleResource::getUrl('edit', ['record' => $this->vehicle]), escape: false)
        ->assertSee('Vehicle settings');

    Livewire::actingAs($user)->test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertDontSee("mountAction('addFuel')", escape: false)
        ->assertSee("mountAction('addRecord')", escape: false);

    Livewire::actingAs($user)->test(ManageVehicleLog::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertSee('Repair')
        ->assertDontSee('Tax & fees');

    // A hidden tab's address (an old bookmark) goes to the overview.
    $this->actingAs($user)->get(VehicleResource::getUrl('fuel', ['record' => $this->vehicle]))
        ->assertRedirect(VehicleResource::getUrl('view', ['record' => $this->vehicle]));
});

it('keeps at least one log entry type', function () {
    Livewire::test(Settings::class)
        ->fillForm(['vehicle_tabs' => ['log'], 'record_categories' => []])
        ->call('save')
        ->assertHasFormErrors(['record_categories' => 'required']);
});

it('shows everything until a user hides something', function () {
    expect(collect(VehicleTab::cases())->every(fn (VehicleTab $tab) => $this->user->showsVehicleTab($tab)))->toBeTrue()
        ->and($this->user->recordCategories())->toBe(RecordCategory::cases());
});
