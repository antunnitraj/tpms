<?php

use App\Enums\PlanStatus;
use App\Enums\RecordCategory;
use App\Enums\RepeatUnit;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\Supply;
use App\Services\Plans;
use App\Services\Supplies;
use Carbon\CarbonImmutable;

it('turns a finished plan into a linked log entry and completes its reminders', function () {
    $plan = Plan::factory()->create(['category' => RecordCategory::Repair, 'cost' => 120]);
    $reminder = Reminder::factory()->create([
        'vehicle_id' => $plan->vehicle_id, 'due_date' => '2026-01-01', 'repeat_every' => 6, 'repeat_unit' => RepeatUnit::Month,
    ]);
    $plan->reminders()->attach($reminder);

    $supply = Supply::factory()->create(['vehicle_id' => $plan->vehicle_id]);
    Supplies::purchase($supply, 2, 20, CarbonImmutable::parse('2025-12-01'));
    Supplies::use($supply, 1, $plan, CarbonImmutable::parse('2025-12-01'));

    $record = Plans::complete($plan, CarbonImmutable::parse('2026-01-05'), 123_456);

    expect($plan->fresh())
        ->status->toBe(PlanStatus::Done)
        ->completed_record_id->toBe($record->id)
        ->and($record)
        ->category->toBe(RecordCategory::Repair)
        ->mileage->toBe(123_456)
        ->and((float) $record->cost)->toBe(120.0)
        ->and($record->supplyUsages()->count())->toBe(1)
        ->and($plan->supplyUsages()->count())->toBe(0)
        ->and($reminder->fresh()->due_date->toDateString())->toBe('2026-07-05');
});
