<?php

namespace App\Console\Commands;

use App\Enums\Urgency;
use App\Events\ReminderBecameDue;
use App\Models\Reminder;
use App\Services\Odometer;
use App\Services\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckRemindersCommand extends Command
{
    protected $signature = 'reminders:check';

    protected $description = 'Dispatch an event for every reminder that became more urgent since the last check';

    public function handle(): int
    {
        $thresholds = [
            'urgent_days' => config('tpms.reminders.urgent_days'),
            'very_urgent_days' => config('tpms.reminders.very_urgent_days'),
            'urgent_distance' => config('tpms.reminders.urgent_distance'),
            'very_urgent_distance' => config('tpms.reminders.very_urgent_distance'),
        ];
        $mileages = [];
        $dispatched = 0;

        Reminder::query()->open()->with('vehicle')->each(function (Reminder $reminder) use ($thresholds, &$mileages, &$dispatched) {
            if (Reminders::isSnoozed($reminder)) {
                return;
            }

            $mileages[$reminder->vehicle_id] ??= Odometer::current($reminder->vehicle);
            $urgency = Reminders::urgency($reminder, $mileages[$reminder->vehicle_id], $thresholds);
            $key = "reminders:urgency:{$reminder->getKey()}";
            $previous = Urgency::tryFrom((string) Cache::get($key)) ?? Urgency::Ok;

            if ($urgency->severity() > $previous->severity() && $urgency !== Urgency::Ok) {
                ReminderBecameDue::dispatch($reminder, $urgency);
                $dispatched++;
            }

            Cache::forever($key, $urgency->value);
        });

        $this->info("{$dispatched} reminder(s) became more urgent.");

        return self::SUCCESS;
    }
}
