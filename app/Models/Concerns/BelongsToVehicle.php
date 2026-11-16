<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToVehicle
{
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('vehicle', fn (Builder $vehicles) => $vehicles->accessibleBy($user));
    }

    public function isAccessibleBy(User $user): bool
    {
        return $this->vehicle?->isAccessibleBy($user) ?? false;
    }
}
