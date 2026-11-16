<?php

namespace App\Models;

use App\Enums\RecordCategory;
use App\Models\Concerns\BelongsToVehicle;
use App\Models\Concerns\ConsumesSupplies;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;

/** A log entry: service, repair, upgrade, or tax/fee. */
class Record extends Model implements HasMedia
{
    use BelongsToVehicle, ConsumesSupplies, HasAttachments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => RecordCategory::class,
            'date' => 'date',
            'mileage' => 'integer',
            'cost' => 'decimal:3',
            'links' => 'array',
            'tags' => 'array',
        ];
    }

    public function reminders(): BelongsToMany
    {
        return $this->belongsToMany(Reminder::class);
    }
}
