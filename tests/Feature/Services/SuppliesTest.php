<?php

use App\Models\Record;
use App\Models\Supply;
use App\Services\Supplies;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('uses supplies at the average unit cost and restores stock when the entry is deleted', function () {
    $supply = Supply::factory()->create();
    $date = CarbonImmutable::parse('2026-01-01');
    Supplies::purchase($supply, 5, 50, $date);
    Supplies::purchase($supply, 5, 70, $date);

    $record = Record::factory()->create();
    Supplies::use($supply, 4, $record, $date);

    $supply = Supply::query()->withStock()->find($supply->id);
    expect($supply->stock())->toBe(6.0)
        ->and($supply->value())->toBe(72.0)
        ->and((float) $record->supplyUsages()->first()->cost)->toBe(-48.0);

    $record->delete();

    expect(Supply::query()->withStock()->find($supply->id)->stock())->toBe(10.0);
});

it('refuses to use more than is in stock', function () {
    $supply = Supply::factory()->create();
    Supplies::purchase($supply, 1, 10, CarbonImmutable::now());

    Supplies::use($supply, 2, Record::factory()->create(), CarbonImmutable::now());
})->throws(ValidationException::class);
