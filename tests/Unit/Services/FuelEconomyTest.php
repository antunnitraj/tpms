<?php

use App\Services\FuelEconomy;

function fill(int $id, string $date, ?int $mileage, float $quantity, bool $full = true, bool $missed = false, float $cost = 0): array
{
    return ['id' => $id, 'date' => $date, 'mileage' => $mileage, 'quantity' => $quantity, 'cost' => $cost,
        'is_full' => $full, 'missed_previous' => $missed, 'soc_start' => null, 'soc_end' => null];
}

it('computes economy between full fill-ups', function () {
    $result = FuelEconomy::calculate([
        fill(1, '2025-01-01', 1000, 40),
        fill(2, '2025-01-10', 1500, 25),
        fill(3, '2025-01-20', 2100, 40),
    ]);

    expect($result['rows'][1])->toBe(['distance' => null, 'economy' => null])
        ->and($result['rows'][2]['distance'])->toBe(500)
        ->and($result['rows'][2]['economy'])->toBe(20.0)
        ->and($result['rows'][3]['economy'])->toBe(15.0)
        ->and($result['average'])->toEqualWithDelta(1100 / 65, 1e-9)
        ->and($result['best'])->toBe(20.0)
        ->and($result['worst'])->toBe(15.0)
        ->and($result['distance'])->toBe(1100);
});

it('folds partial fills into the next full fill', function () {
    $result = FuelEconomy::calculate([
        fill(1, '2025-01-01', 1000, 40),
        fill(2, '2025-01-05', 1200, 10, full: false),
        fill(3, '2025-01-10', 1600, 20),
    ]);

    expect($result['rows'][2]['economy'])->toBeNull()
        ->and($result['rows'][3]['economy'])->toBe(20.0)
        ->and($result['average'])->toBe(20.0);
});

it('breaks the chain at a missed fill-up and ignores the partials before it', function () {
    $result = FuelEconomy::calculate([
        fill(1, '2025-01-01', 1000, 40),
        fill(2, '2025-01-05', 1200, 10, full: false),
        fill(3, '2025-01-20', 2000, 35, missed: true),
        fill(4, '2025-01-30', 2400, 20),
    ]);

    expect($result['rows'][3]['economy'])->toBeNull()
        ->and($result['rows'][4]['economy'])->toBe(20.0)
        // Only the 2000→2400 segment is complete; the partial before the gap is discarded.
        ->and($result['average'])->toBe(20.0);
});

it('sorts fill-ups by date then mileage before computing', function () {
    $result = FuelEconomy::calculate([
        fill(2, '2025-01-10', 1500, 25),
        fill(1, '2025-01-01', 1000, 40),
    ]);

    expect($result['rows'][2]['economy'])->toBe(20.0);
});

it('computes electric efficiency from state of charge', function () {
    $result = FuelEconomy::calculate([
        ['id' => 1, 'date' => '2025-01-01', 'mileage' => 1000, 'quantity' => 30, 'is_full' => true, 'missed_previous' => false, 'soc_start' => 20, 'soc_end' => 80],
        // 30 kWh for 60% → 50 kWh pack; used 80%→30% = 25 kWh over 150 km.
        ['id' => 2, 'date' => '2025-01-05', 'mileage' => 1150, 'quantity' => 25, 'is_full' => true, 'missed_previous' => false, 'soc_start' => 30, 'soc_end' => 80],
    ], electric: true);

    expect($result['rows'][2]['economy'])->toBe(6.0);
});

it('starts over after a missed fill-up even when that fill-up has no odometer', function () {
    $result = FuelEconomy::calculate([
        fill(1, '2025-01-01', 1000, 40),
        fill(2, '2025-01-05', 1300, 15, full: false),
        fill(3, '2025-01-08', null, 10, missed: true),
        fill(4, '2025-01-12', 1900, 20),
    ]);

    expect($result['rows'][4]['economy'])->toBeNull()
        ->and($result['average'])->toBeNull();
});

it('counts a tank filled in December towards the next year\'s economy', function () {
    $fills = [
        fill(1, '2025-12-28', 10_000, 30),
        fill(2, '2026-01-10', 10_300, 20, full: false),
        fill(3, '2026-01-20', 10_700, 25),
    ];

    expect(FuelEconomy::averageForYear($fills, false, 2026))->toEqualWithDelta(700 / 45, 1e-9)
        ->and(FuelEconomy::averageForYear($fills, false, 2025))->toBeNull();
});
