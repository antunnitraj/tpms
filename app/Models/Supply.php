<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/** A part or consumable kept for a vehicle. Stock and value are the sums of its ledger movements. */
class Supply extends Model implements HasMedia
{
    use HasAttachments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SupplyMovement::class);
    }

    public function scopeWithStock(Builder $query): Builder
    {
        return $query->withSum('movements as stock', 'quantity')->withSum('movements as value', 'cost');
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('vehicle', fn (Builder $vehicles) => $vehicles->accessibleBy($user));
    }

    public function isAccessibleBy(User $user): bool
    {
        return (bool) $this->vehicle?->isAccessibleBy($user);
    }

    // Read the withStock() sums from the raw attributes: `$this->stock` would make
    // Eloquent call stock() as if it were a relationship when the sum isn't loaded.
    public function stock(): float
    {
        return (float) ($this->getAttributes()['stock'] ?? $this->movements()->sum('quantity'));
    }

    public function value(): float
    {
        return (float) ($this->getAttributes()['value'] ?? $this->movements()->sum('cost'));
    }

    public function unitCost(): float
    {
        $stock = $this->stock();

        return $stock > 0 ? $this->value() / $stock : 0.0;
    }
}
