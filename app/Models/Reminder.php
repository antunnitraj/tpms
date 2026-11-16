<?php

namespace App\Models;

use App\Enums\RepeatUnit;
use App\Models\Concerns\BelongsToVehicle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reminder extends Model
{
    use BelongsToVehicle, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'due_mileage' => 'integer',
            'repeat_every' => 'integer',
            'repeat_unit' => RepeatUnit::class,
            'repeat_distance' => 'integer',
            'fixed_schedule' => 'boolean',
            'snoozed_until' => 'date',
            'last_completed_on' => 'date',
            'last_completed_mileage' => 'integer',
            'completed_at' => 'datetime',
            'tags' => 'array',
        ];
    }

    public function records(): BelongsToMany
    {
        return $this->belongsToMany(Record::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class);
    }

    public function isRecurring(): bool
    {
        return ($this->repeat_every && $this->repeat_unit) || $this->repeat_distance;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('completed_at');
    }

    public function describeRepeat(): ?string
    {
        $parts = [];

        if ($this->repeat_every && $this->repeat_unit) {
            // "every year", "every 2 years" (not "every 1 years").
            $parts[] = trans_choice(match ($this->repeat_unit) {
                RepeatUnit::Day => 'every day|every :count days',
                RepeatUnit::Week => 'every week|every :count weeks',
                RepeatUnit::Month => 'every month|every :count months',
                RepeatUnit::Year => 'every year|every :count years',
            }, $this->repeat_every, ['count' => $this->repeat_every]);
        }

        if ($this->repeat_distance) {
            $parts[] = __('every :distance :unit', [
                'distance' => number_format($this->repeat_distance),
                'unit' => $this->vehicle?->distanceUnit()->short() ?? 'km',
            ]);
        }

        return $parts ? implode(' '.__('or').' ', $parts) : null;
    }
}
