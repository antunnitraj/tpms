<?php

use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('offers the LubeLogger import on an empty garage to admins only', function (bool $admin, bool $sees) {
    $this->actingAs(User::factory()->create(['is_admin' => $admin]));

    $page = Livewire::test(ListVehicles::class)->assertSee('Your garage is empty');

    $sees ? $page->assertSee('Import from LubeLogger') : $page->assertDontSee('Import from LubeLogger');
})->with([
    'admin' => [true, true],
    'regular user' => [false, false],
]);

it('adds a fuel-up straight from a garage card', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $vehicle = vehicleFor($user);

    Livewire::test(ListVehicles::class)
        ->assertSee($vehicle->display_name)
        ->callAction(TestAction::make('addFuel')->arguments(['vehicle' => $vehicle->id]),
            data: ['date' => '2026-01-05', 'mileage' => 12_000, 'quantity' => 40, 'cost' => 62.4, 'is_full' => true, 'missed_previous' => false])
        ->assertHasNoActionErrors();

    expect($vehicle->fuelRecords()->sole()->mileage)->toBe(12_000);
});

it('only adds to vehicles the user can open', function () {
    $this->actingAs(User::factory()->create());
    $someoneElses = vehicleFor(User::factory()->create());

    expect(fn () => Livewire::test(ListVehicles::class)
        ->callAction(TestAction::make('addFuel')->arguments(['vehicle' => $someoneElses->id]),
            data: ['date' => '2026-01-05', 'mileage' => 12_000, 'quantity' => 40, 'cost' => 62.4, 'is_full' => true, 'missed_previous' => false]))
        ->toThrow(ModelNotFoundException::class);

    expect($someoneElses->fuelRecords()->count())->toBe(0);
});

it('searches for a typed % literally', function () {
    $this->actingAs($user = User::factory()->create());
    vehicleFor($user);

    Livewire::test(ListVehicles::class)
        ->set('search', '%')
        ->assertSee('No vehicles match your search.');
});

it('refuses vehicle values that cannot be right', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListVehicles::class)
        ->callAction('create', data: [
            'make' => 'Toyota', 'model' => 'Corolla', 'fuel_type' => 'electric', 'distance_unit' => 'km', 'fuel_unit' => 'l',
            'purchase_date' => now()->addYear()->toDateString(), 'purchase_price' => -500,
        ])
        ->assertHasActionErrors(['fuel_unit', 'purchase_date', 'purchase_price']);
});
