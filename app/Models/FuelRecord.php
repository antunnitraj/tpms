<?php

namespace App\Models;

use App\Models\Concerns\BelongsToVehicle;
use App\Models\Concerns\ConsumesSupplies;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

class FuelRecord extends Model implements HasMedia
{
    use BelongsToVehicle, ConsumesSupplies, HasAttachments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mileage' => 'integer',
            'quantity' => 'decimal:3',
            'cost' => 'decimal:3',
            'is_full' => 'boolean',
            'missed_previous' => 'boolean',
            'soc_start' => 'integer',
            'soc_end' => 'integer',
            'links' => 'array',
            'tags' => 'array',
        ];
    }

    public function unitPrice(): ?float
    {
        return (float) $this->quantity > 0 ? (float) $this->cost / (float) $this->quantity : null;
    }
}
