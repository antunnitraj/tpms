<?php

use App\Filament\Resources\Vehicles\Pages\ManageVehicleFuel;
use App\Filament\Resources\Vehicles\Pages\ManageVehiclePlans;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Schemas\Fields;
use App\Models\FuelRecord;
use App\Models\Plan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->vehicle = vehicleFor($this->user);
    $this->overview = fn () => Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()]);
});

it('reads a comma as the decimal point, as many phone keyboards type it', function () {
    ($this->overview)()
        ->callAction('addFuel', data: ['date' => '2026-01-05', 'mileage' => 12_000, 'quantity' => '41,25', 'cost' => '62,40'])
        ->assertHasNoActionErrors();

    ($this->overview)()
        ->callAction('addRecord', data: ['date' => '2026-01-06', 'description' => 'Wipers', 'cost' => ' 12,5 '])
        ->assertHasNoActionErrors();

    expect((float) $this->vehicle->fuelRecords()->sole()->quantity)->toBe(41.25)
        ->and((float) $this->vehicle->fuelRecords()->sole()->cost)->toBe(62.4)
        ->and((float) $this->vehicle->records()->sole()->cost)->toBe(12.5);
});

it('still refuses what is not a number', function () {
    ($this->overview)()
        ->callAction('addFuel', data: ['date' => '2026-01-05', 'quantity' => '41,2,5', 'cost' => 'a lot'])
        ->assertHasActionErrors(['quantity' => 'numeric', 'cost' => 'numeric']);
});

it('asks for the amount, then the total, then the price, typed like a till on a phone keyboard', function () {
    $form = ($this->overview)()->mountAction('addFuel')->instance()->getSchema('mountedActionSchema0');
    $fields = collect($form->getFlatFields())->filter(fn ($field) => in_array($field->getName(), ['quantity', 'cost', 'unit_price']))->values();

    expect($fields->map->getName()->all())->toBe(['quantity', 'cost', 'unit_price'])
        // Text fields with the decimal keypad: a number field would refuse a comma.
        ->and($fields->map->getType()->unique()->all())->toBe(['text'])
        ->and($fields->map->getInputMode()->unique()->all())->toBe(['decimal'])
        ->and($fields->map(fn ($field) => $field->getExtraAlpineAttributes()['x-tpms-decimal'])->all())->toBe(['2', '2', '3']);
});

it('works out the price from the total, and keeps the total when the amount changes', function () {
    ($this->overview)()
        ->mountAction('addFuel')
        ->fillForm(['quantity' => '40.00', 'cost' => '62.40'], 'mountedActionSchema0')
        ->assertActionDataSet(['unit_price' => '1.560'])
        ->fillForm(['quantity' => '48.00'], 'mountedActionSchema0')
        ->assertActionDataSet(['cost' => '62.40', 'unit_price' => '1.300']);
});

it('shows a saved fuel-up the way its numbers are typed', function () {
    $fuel = FuelRecord::factory()->for($this->vehicle)->create(['quantity' => 40, 'cost' => 62.4]);

    Livewire::test(ManageVehicleFuel::class, ['record' => $this->vehicle->getRouteKey()])
        ->mountAction(TestAction::make('edit')->table($fuel))
        ->assertActionDataSet(['quantity' => '40.00', 'cost' => '62.40', 'unit_price' => '1.560']);
});

it('formats till numbers without dropping digits', function () {
    expect(Fields::normalizeDecimal(' 12,5 '))->toBe('12.5')
        ->and(Fields::normalizeDecimal(''))->toBeNull()
        ->and(Fields::withDecimals('40.000', 2))->toBe('40.00')
        ->and(Fields::withDecimals(1.5, 3))->toBe('1.500')
        ->and(Fields::withDecimals('12.345', 2))->toBe('12.345')
        ->and(Fields::withDecimals(null, 2))->toBeNull();
});

it('shows all of a vehicle\'s plans on one page', function () {
    Plan::factory()->for($this->vehicle)->count(30)->create();

    Livewire::test(ManageVehiclePlans::class, ['record' => $this->vehicle->getRouteKey()])
        ->assertCanSeeTableRecords(Plan::query()->where('status', 'planned')->get())
        ->assertCountTableRecords(Plan::query()->where('status', 'planned')->count());
});
