<?php

namespace App\Support;

use App\Models\Reminder;
use App\Services\Reminders;

class ReminderText
{
    /** "in 12 days · in 1,200 km", "3 days ago", "today" … */
    public static function due(Reminder $reminder, ?int $currentMileage): string
    {
        $parts = [];

        if ($reminder->due_date) {
            $days = Reminders::daysUntil($reminder);
            $parts[] = match (true) {
                $days === 0 => __('today'),
                $days === 1 => __('tomorrow'),
                $days < 0 => trans_choice(':count day ago|:count days ago', -$days, ['count' => -$days]),
                default => trans_choice('in :count day|in :count days', $days, ['count' => $days]),
            };
        }

        if ($reminder->due_mileage !== null) {
            $unit = $reminder->vehicle->distanceUnit();
            $parts[] = $currentMileage !== null
                ? ($reminder->due_mileage >= $currentMileage
                    ? __('in :distance', ['distance' => Format::distance($reminder->due_mileage - $currentMileage, $unit)])
                    : __(':distance over', ['distance' => Format::distance($currentMileage - $reminder->due_mileage, $unit)]))
                : __('at :distance', ['distance' => Format::distance($reminder->due_mileage, $unit)]);
        }

        return implode(' · ', $parts) ?: __('no due date');
    }
}
