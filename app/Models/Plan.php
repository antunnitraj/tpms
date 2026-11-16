<?php

namespace App\Models;

use App\Enums\PlanPriority;
use App\Enums\PlanStatus;
use App\Enums\RecordCategory;
use App\Models\Concerns\BelongsToVehicle;
use App\Models\Concerns\ConsumesSupplies;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;

class Plan extends Model implements HasMedia
{
    use BelongsToVehicle, ConsumesSupplies, HasAttachments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => RecordCategory::class,
            'priority' => PlanPriority::class,
            'status' => PlanStatus::class,
            'cost' => 'decimal:3',
            'completed_at' => 'datetime',
            'tags' => 'array',
        ];
    }

    public function reminders(): BelongsToMany
    {
        return $this->belongsToMany(Reminder::class);
    }

    public function completedRecord(): BelongsTo
    {
        return $this->belongsTo(Record::class, 'completed_record_id');
    }
}
