<?php

namespace App\Models\Concerns;

use App\Enums\SupplyMovementType;
use App\Models\SupplyMovement;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Entries that can use up supplies. Usage is stored as negative ledger
 * movements, so deleting the entry deletes them and restores the stock.
 */
trait ConsumesSupplies
{
    public static function bootConsumesSupplies(): void
    {
        // A block, not an arrow function: a "deleting" listener that returns a value (the
        // row count) stops the listeners after it, which left attached files on disk.
        static::deleting(function ($model): void {
            $model->supplyUsages()->delete();
        });
    }

    public function supplyUsages(): MorphMany
    {
        return $this->morphMany(SupplyMovement::class, 'consumer')
            ->where('type', SupplyMovementType::Use->value);
    }
}
