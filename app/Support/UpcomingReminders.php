<?php

namespace App\Support;

use App\Enums\Urgency;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Odometer;
use App\Services\Reminders;
use Illuminate\Support\Collection;

/** Reminders that need attention across all of a user's (unsold) vehicles. */
class UpcomingReminders
{
    /** @return Collection<int, array{reminder: Reminder, urgency: Urgency, due: string, days: int}> */
    public static function for(User $user, int $limit = 6): Collection
    {
        $thresholds = $user->reminderThresholds();
        $mileages = [];

        return Reminder::query()->accessibleBy($user)->open()->with('vehicle.media')->get()
            ->reject(fn (Reminder $reminder) => Reminders::isSnoozed($reminder) || $reminder->vehicle->isSold())
            ->map(function (Reminder $reminder) use (&$mileages, $thresholds) {
                $mileage = $mileages[$reminder->vehicle_id] ??= Odometer::current($reminder->vehicle);

                return [
                    'reminder' => $reminder,
                    'urgency' => Reminders::urgency($reminder, $mileage, $thresholds),
                    'due' => ReminderText::due($reminder, $mileage),
                    'days' => Reminders::daysUntil($reminder) ?? PHP_INT_MAX,
                ];
            })
            ->filter(fn (array $row) => $row['urgency']->severity() >= Urgency::Urgent->severity())
            ->sortBy([
                fn ($a, $b) => $b['urgency']->severity() <=> $a['urgency']->severity(),
                fn ($a, $b) => $a['days'] <=> $b['days'],
            ])
            ->take($limit)
            ->values();
    }
}
