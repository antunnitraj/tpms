<?php

namespace App\Models;

use App\Models\Concerns\BelongsToVehicle;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;

/** Detachable equipment such as a set of tyres or a trailer. */
class Equipment extends Model implements HasMedia
{
    use BelongsToVehicle, HasAttachments, HasFactory;

    protected $table = 'equipment';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(EquipmentPeriod::class)->orderBy('installed_on');
    }

    public function openPeriod(): HasOne
    {
        return $this->hasOne(EquipmentPeriod::class)->whereNull('removed_on')->latestOfMany('installed_on');
    }
}
