<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Record;
use App\Models\Supply;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class Plans
{
    /**
     * Finish a plan: create the log entry it describes (with its attachments
     * and supply usage), complete linked reminders and keep a link back.
     */
    public static function complete(Plan $plan, CarbonInterface $date, ?int $mileage = null): Record
    {
        return DB::transaction(function () use ($plan, $date, $mileage) {
            // Two taps on "Done" (or two people at once) must not log the work twice.
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());
            if ($plan->status === PlanStatus::Done && $plan->completedRecord) {
                return $plan->completedRecord;
            }

            $record = $plan->vehicle->records()->create([
                'category' => $plan->category,
                'date' => $date->toDateString(),
                'mileage' => $mileage,
                'description' => $plan->description,
                'cost' => $plan->cost ?? 0,
                'notes' => $plan->notes,
            ]);

            $plan->supplyUsages()->update([
                'consumer_type' => $record->getMorphClass(),
                'consumer_id' => $record->getKey(),
                'date' => $date->toDateString(),
            ]);

            foreach ($plan->getMedia('attachments') as $media) {
                $media->copy($record, 'attachments');
            }

            foreach ($plan->reminders as $reminder) {
                Reminders::complete($reminder, $date, $mileage, $record);
            }

            $plan->update([
                'status' => PlanStatus::Done,
                'completed_record_id' => $record->getKey(),
                'completed_at' => now(),
            ]);

            return $record;
        });
    }
}
