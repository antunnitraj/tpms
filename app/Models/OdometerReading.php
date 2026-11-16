<?php

namespace App\Models;

use App\Models\Concerns\BelongsToVehicle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A manual odometer reading; other entries with a mileage also count as readings. */
class OdometerReading extends Model
{
    use BelongsToVehicle, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mileage' => 'integer',
            'tags' => 'array',
        ];
    }
}
