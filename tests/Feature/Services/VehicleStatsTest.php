<?php

use App\Enums\RecordCategory;
use App\Models\FuelRecord;
use App\Models\OdometerReading;
use App\Models\Record;
use App\Services\Odometer;
use App\Services\VehicleStats;

it('derives the odometer from every entry with a mileage', function () {
    $vehicle = vehicleFor();
    Record::factory()->for($vehicle)->create(['date' => '2025-01-10', 'mileage' => 10_000, 'cost' => 100]);
    FuelRecord::factory()->for($vehicle)->create(['date' => '2025-06-01', 'mileage' => 15_000, 'cost' => 60]);
    OdometerReading::query()->create(['vehicle_id' => $vehicle->id, 'date' => '2026-01-01', 'mileage' => 20_000]);
    Record::factory()->for($vehicle)->create(['date' => '2025-03-01', 'mileage' => null, 'cost' => 40, 'category' => RecordCategory::TaxFee]);

    expect(Odometer::current($vehicle))->toBe(20_000)
        ->and(Odometer::first($vehicle))->toBe(10_000)
        ->and(Odometer::timeline($vehicle)->pluck('distance')->all())->toBe([null, 5_000, 5_000])
        ->and(Odometer::distanceBetween($vehicle, now()->setDate(2025, 1, 1), now()->setDate(2025, 12, 31)))->toBe(5_000);

    $summary = VehicleStats::summary($vehicle);
    expect($summary['total_cost'])->toBe(200.0)
        ->and($summary['distance'])->toBe(10_000)
        ->and($summary['cost_per_distance'])->toBe(0.02);

    expect(VehicleStats::costByCategory($vehicle, 2025))->toMatchArray(['service' => 100.0, 'tax_fee' => 40.0, 'fuel' => 60.0]);
});

it('counts only the chosen cost categories', function () {
    $vehicle = vehicleFor();
    Record::factory()->for($vehicle)->create(['date' => '2025-01-10', 'mileage' => 10_000, 'cost' => 100, 'category' => RecordCategory::Service]);
    Record::factory()->for($vehicle)->create(['date' => '2025-02-10', 'mileage' => 11_000, 'cost' => 300, 'category' => RecordCategory::Repair]);
    FuelRecord::factory()->for($vehicle)->create(['date' => '2025-03-01', 'mileage' => 12_000, 'cost' => 60]);

    expect(VehicleStats::costByCategory($vehicle, categories: ['fuel', 'service', 'bogus']))->toBe(['service' => 100.0, 'fuel' => 60.0])
        ->and(VehicleStats::summary($vehicle, categories: ['repair'])['total_cost'])->toBe(300.0)
        ->and(VehicleStats::summary($vehicle, categories: ['repair'])['cost_per_distance'])->toBe(0.15)
        ->and(array_keys(VehicleStats::timeline($vehicle, 2025, ['service', 'fuel'])['series']))->toBe(['service', 'fuel'])
        ->and(VehicleStats::totalCost($vehicle, categories: []))->toBe(0.0)
        ->and(VehicleStats::totalCost($vehicle))->toBe(460.0);
});
