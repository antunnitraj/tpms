<?php

use App\Enums\RepeatUnit;
use App\Enums\Urgency;
use App\Models\Record;
use App\Models\Reminder;
use App\Services\Reminders;
use Carbon\CarbonImmutable;

$thresholds = ['urgent_days' => 30, 'very_urgent_days' => 7, 'urgent_distance' => 1000, 'very_urgent_distance' => 300];

it('rates urgency by date', function (string $due, Urgency $expected) use ($thresholds) {
    $reminder = Reminder::factory()->make(['due_date' => $due]);

    expect(Reminders::urgency($reminder, null, $thresholds, CarbonImmutable::parse('2026-01-01')))->toBe($expected);
})->with([
    'past due' => ['2025-12-31', Urgency::PastDue],
    'due today is very urgent, not past due' => ['2026-01-01', Urgency::VeryUrgent],
    'within a week' => ['2026-01-07', Urgency::VeryUrgent],
    'within a month' => ['2026-01-20', Urgency::Urgent],
    'later' => ['2026-03-01', Urgency::Ok],
]);

it('rates urgency by mileage and takes the worse of date and mileage', function () use ($thresholds) {
    $reminder = Reminder::factory()->make(['due_date' => '2026-06-01', 'due_mileage' => 100_000]);
    $today = CarbonImmutable::parse('2026-01-01');

    expect(Reminders::urgency($reminder, 98_000, $thresholds, $today))->toBe(Urgency::Ok)
        ->and(Reminders::urgency($reminder, 99_500, $thresholds, $today))->toBe(Urgency::Urgent)
        ->and(Reminders::urgency($reminder, 99_800, $thresholds, $today))->toBe(Urgency::VeryUrgent)
        ->and(Reminders::urgency($reminder, 100_001, $thresholds, $today))->toBe(Urgency::PastDue);
});

it('advances a recurring reminder from the completion date and mileage', function () {
    $reminder = Reminder::factory()->create([
        'due_date' => '2026-01-01', 'due_mileage' => 100_000,
        'repeat_every' => 1, 'repeat_unit' => RepeatUnit::Year, 'repeat_distance' => 15_000,
    ]);

    Reminders::complete($reminder, CarbonImmutable::parse('2025-12-20'), 99_000);

    expect($reminder->fresh())
        ->due_date->toDateString()->toBe('2026-12-20')
        ->due_mileage->toBe(114_000)
        ->completed_at->toBeNull()
        ->last_completed_on->toDateString()->toBe('2025-12-20');
});

it('keeps a fixed schedule anchored to the previous due values', function () {
    $reminder = Reminder::factory()->create([
        'due_date' => '2026-01-31', 'repeat_every' => 1, 'repeat_unit' => RepeatUnit::Month, 'fixed_schedule' => true,
    ]);

    Reminders::complete($reminder, CarbonImmutable::parse('2026-02-10'));

    expect($reminder->fresh()->due_date->toDateString())->toBe('2026-02-28');
});

it('closes one-off reminders and links the record that completed them', function () {
    $reminder = Reminder::factory()->create();
    $record = Record::factory()->create(['vehicle_id' => $reminder->vehicle_id]);

    Reminders::complete($reminder, CarbonImmutable::parse('2026-01-01'), 5000, $record);

    expect($reminder->fresh()->isCompleted())->toBeTrue()
        ->and($reminder->records()->pluck('records.id')->all())->toBe([$record->id]);
});
