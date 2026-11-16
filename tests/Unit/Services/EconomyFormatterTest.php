<?php

use App\Enums\DistanceUnit;
use App\Enums\EconomyFormat;
use App\Enums\FuelUnit;
use App\Services\EconomyFormatter;

it('shows L/100 km for metric vehicles by default', function () {
    $result = EconomyFormatter::format(20.0, DistanceUnit::Kilometers, FuelUnit::Liters);

    expect($result['value'])->toBe(5.0)->and($result['unit'])->toBe('L/100 km')->and($result['lower_is_better'])->toBeTrue();
});

it('converts to MPG', function () {
    // 20 km/L ≈ 47.04 US mpg ≈ 56.49 UK mpg
    expect(EconomyFormatter::format(20.0, DistanceUnit::Kilometers, FuelUnit::Liters, EconomyFormat::MpgUs)['value'])->toEqualWithDelta(47.04, 0.01)
        ->and(EconomyFormatter::format(20.0, DistanceUnit::Kilometers, FuelUnit::Liters, EconomyFormat::MpgUk)['value'])->toEqualWithDelta(56.49, 0.01);
});

it('keeps native mpg for imperial vehicles', function () {
    $result = EconomyFormatter::format(30.0, DistanceUnit::Miles, FuelUnit::UsGallons);

    expect($result['value'])->toEqualWithDelta(30.0, 1e-9)->and($result['unit'])->toBe('mpg');
});

it('uses UK mpg for miles with litres (LubeLogger UK mode)', function () {
    // 10 miles per litre = 45.46 mpg (UK)
    expect(EconomyFormatter::format(10.0, DistanceUnit::Miles, FuelUnit::Liters)['value'])->toEqualWithDelta(45.46, 0.01);
});

it('falls back to native units for electricity and engine hours', function () {
    expect(EconomyFormatter::format(6.0, DistanceUnit::Kilometers, FuelUnit::KilowattHours)['unit'])->toBe('kWh/100 km')
        ->and(EconomyFormatter::format(4.0, DistanceUnit::Miles, FuelUnit::KilowattHours)['unit'])->toBe('mi/kWh')
        ->and(EconomyFormatter::format(0.5, DistanceUnit::Hours, FuelUnit::Liters, EconomyFormat::MpgUs)['unit'])->toBe('L/100 h');
});
