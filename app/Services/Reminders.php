<?php

namespace App\Services;

use App\Enums\RepeatUnit;
use App\Enums\Urgency;
use App\Models\Record;
use App\Models\Reminder;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class Reminders
{
    /**
     * How urgent a reminder is. Date and mileage are checked independently and
     * the worse one wins. Due today counts as very urgent, not past due.
     *
     * @param  array{urgent_days:int, very_urgent_days:int, urgent_distance:int, very_urgent_distance:int}  $thresholds
     */
    public static function urgency(Reminder $reminder, ?int $currentMileage, array $thresholds, ?CarbonInterface $today = null): Urgency
    {
        if ($reminder->isCompleted()) {
            return Urgency::Done;
        }

        $today ??= Clock::today();
        $levels = [Urgency::Ok];

        if ($reminder->due_date !== null) {
            $days = static::daysUntil($reminder, $today);
            $levels[] = match (true) {
                $days < 0 => Urgency::PastDue,
                $days < max(1, $thresholds['very_urgent_days']) => Urgency::VeryUrgent,
                $days < $thresholds['urgent_days'] => Urgency::Urgent,
                default => Urgency::Ok,
            };
        }

        if ($reminder->due_mileage !== null && $currentMileage !== null) {
            $remaining = $reminder->due_mileage - $currentMileage;
            $levels[] = match (true) {
                $remaining < 0 => Urgency::PastDue,
                $remaining < $thresholds['very_urgent_distance'] => Urgency::VeryUrgent,
                $remaining < $thresholds['urgent_distance'] => Urgency::Urgent,
                default => Urgency::Ok,
            };
        }

        return collect($levels)->sortByDesc(fn (Urgency $u) => $u->severity())->first();
    }

    public static function urgencyFor(Reminder $reminder, User $user, ?int $currentMileage = null): Urgency
    {
        $currentMileage ??= Odometer::current($reminder->vehicle);

        return static::urgency($reminder, $currentMileage, $user->reminderThresholds());
    }

    public static function daysUntil(Reminder $reminder, ?CarbonInterface $today = null): ?int
    {
        if ($reminder->due_date === null) {
            return null;
        }

        $today ??= Clock::today();

        return (int) CarbonImmutable::parse($today->toDateString())->diffInDays(CarbonImmutable::parse($reminder->due_date->toDateString()), false);
    }

    public static function isSnoozed(Reminder $reminder, ?CarbonInterface $today = null): bool
    {
        $today ??= Clock::today();

        return $reminder->snoozed_until !== null && $reminder->snoozed_until->toDateString() > $today->toDateString();
    }

    /**
     * Mark a reminder as done. Recurring reminders move to their next due
     * date/mileage (from the completion, or from the old due values when the
     * schedule is fixed); one-off reminders are closed.
     */
    public static function complete(Reminder $reminder, CarbonInterface $date, ?int $mileage = null, ?Record $record = null): Reminder
    {
        return DB::transaction(function () use ($reminder, $date, $mileage, $record) {
            $date = CarbonImmutable::parse($date->toDateString());

            if ($reminder->isRecurring()) {
                if ($reminder->repeat_every && $reminder->repeat_unit) {
                    $base = $reminder->fixed_schedule && $reminder->due_date
                        ? CarbonImmutable::parse($reminder->due_date->toDateString())
                        : $date;
                    $reminder->due_date = static::addInterval($base, $reminder->repeat_every, $reminder->repeat_unit);
                }

                if ($reminder->repeat_distance) {
                    $base = $reminder->fixed_schedule && $reminder->due_mileage !== null
                        ? $reminder->due_mileage
                        : ($mileage ?? Odometer::current($reminder->vehicle) ?? $reminder->due_mileage);

                    if ($base !== null) {
                        $reminder->due_mileage = $base + $reminder->repeat_distance;
                    }
                }

                // A due date or mileage that has no interval was a one-time limit, and it's done
                // now; left in place it would make the next round overdue at once.
                if (! ($reminder->repeat_every && $reminder->repeat_unit)) {
                    $reminder->due_date = null;
                }
                if (! $reminder->repeat_distance) {
                    $reminder->due_mileage = null;
                }
            } else {
                $reminder->completed_at = now();
            }

            $reminder->last_completed_on = $date;
            $reminder->last_completed_mileage = $mileage;
            $reminder->snoozed_until = null;
            $reminder->save();

            if ($record !== null) {
                $reminder->records()->syncWithoutDetaching([$record->getKey()]);
            }

            return $reminder;
        });
    }

    public static function snooze(Reminder $reminder, CarbonInterface $until): void
    {
        $reminder->update(['snoozed_until' => $until->toDateString()]);
    }

    public static function addInterval(CarbonImmutable $date, int $every, RepeatUnit $unit): CarbonImmutable
    {
        return match ($unit) {
            RepeatUnit::Day => $date->addDays($every),
            RepeatUnit::Week => $date->addWeeks($every),
            RepeatUnit::Month => $date->addMonthsNoOverflow($every),
            RepeatUnit::Year => $date->addYearsNoOverflow($every),
        };
    }
}
