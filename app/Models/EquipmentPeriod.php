<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentPeriod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'installed_mileage' => 'integer',
            'removed_on' => 'date',
            'removed_mileage' => 'integer',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
