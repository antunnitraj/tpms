<?php

namespace App\Models;

use App\Enums\DistanceUnit;
use App\Enums\FuelType;
use App\Enums\FuelUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Vehicle extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'fuel_type' => FuelType::class,
            'distance_unit' => DistanceUnit::class,
            'fuel_unit' => FuelUnit::class,
            'purchase_date' => 'date',
            'sold_date' => 'date',
            'purchase_price' => 'decimal:3',
            'sold_price' => 'decimal:3',
            'tags' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Child rows are removed by FK cascades; delete the ones with files
        // through Eloquent first so the media library removes their files.
        static::deleting(function (Vehicle $vehicle) {
            foreach (['records', 'fuelRecords', 'plans', 'supplies', 'notes', 'equipment'] as $relation) {
                // lazyById, not each(): offset paging skips rows once earlier ones are deleted.
                $vehicle->{$relation}()->lazyById()->each(fn (Model $child) => $child->delete());
            }
        });
    }

    public function registerMediaCollections(): void
    {
        // Always the private media disk: form uploads would otherwise fall back to
        // Filament's default ("local"), where the weekly media clean-up sweeps other folders.
        $this->addMediaCollection('photos')->acceptsMimeTypes([
            'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/avif',
        ])->useDisk('media');
        $this->addMediaCollection('documents')->useDisk('media');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 640, 400)
            ->format('webp')
            ->performOnCollections('photos', 'documents');

        $this->addMediaConversion('preview')
            ->fit(Fit::Max, 1600, 1600)
            ->format('webp')
            ->performOnCollections('photos');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vehicle_user')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function odometerReadings(): HasMany
    {
        return $this->hasMany(OdometerReading::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->getKey()));
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($this->relationLoaded('users')) {
            return $this->users->contains($user);
        }

        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function isSold(): bool
    {
        return $this->sold_date !== null;
    }

    public function getDisplayNameAttribute(): string
    {
        if (filled($this->nickname)) {
            return $this->nickname;
        }

        return trim(implode(' ', array_filter([$this->year, $this->make, $this->model])));
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->year, $this->make, $this->model])));
    }

    public function coverPhoto(): ?Media
    {
        return $this->getFirstMedia('photos');
    }

    public function distanceUnit(): DistanceUnit
    {
        return $this->distance_unit ?? DistanceUnit::Kilometers;
    }

    public function fuelUnit(): FuelUnit
    {
        return $this->fuel_unit ?? FuelUnit::Liters;
    }
}
