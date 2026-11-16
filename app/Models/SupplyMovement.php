<?php

namespace App\Models;

use App\Enums\SupplyMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupplyMovement extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => SupplyMovementType::class,
            'quantity' => 'decimal:3',
            'cost' => 'decimal:3',
        ];
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function consumer(): MorphTo
    {
        return $this->morphTo();
    }
}
