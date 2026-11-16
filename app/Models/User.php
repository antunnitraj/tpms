<?php

namespace App\Models;

use App\Enums\EconomyFormat;
use App\Enums\RecordCategory;
use App\Enums\Theme;
use App\Enums\VehicleTab;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password', 'legacy_sha256', 'is_admin', 'settings', 'import_id',
    ];

    protected $hidden = ['password', 'legacy_sha256', 'remember_token'];

    /** Sign-in names are case-insensitive, so they're stored in lower case and unique as such. */
    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => blank($value) ? null : mb_strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        // Vehicles only this user could open would be left with nobody able to see them:
        // hand them to whoever deletes the account (or another administrator) instead.
        static::deleting(function (User $user) {
            $heir = auth()->user() instanceof User && ! auth()->user()->is($user)
                ? auth()->user()
                : User::query()->where('is_admin', true)->whereKeyNot($user->getKey())->orderBy('id')->first();

            if ($heir === null) {
                return;
            }

            $user->vehicles()->whereDoesntHave('users', fn ($query) => $query->whereKeyNot($user->getKey()))
                ->pluck('vehicles.id')
                ->each(fn (int $id) => $heir->vehicles()->syncWithoutDetaching([$id]));
        });
    }

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'vehicle_user')->withTimestamps();
    }

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return [
            'economy_format' => EconomyFormat::Auto->value,
            'theme' => Theme::DEFAULT->value,
            'urgent_days' => config('tpms.reminders.urgent_days'),
            'very_urgent_days' => config('tpms.reminders.very_urgent_days'),
            'urgent_distance' => config('tpms.reminders.urgent_distance'),
            'very_urgent_distance' => config('tpms.reminders.very_urgent_distance'),
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get(array_replace_recursive(self::defaultSettings(), $this->settings ?? []), $key, $default);
    }

    public function showsVehicleTab(VehicleTab $tab): bool
    {
        return ! in_array($tab->value, $this->settings['hidden_vehicle_tabs'] ?? [], true);
    }

    /** Log entry types this user works with (never none). @return list<RecordCategory> */
    public function recordCategories(): array
    {
        $hidden = $this->settings['hidden_record_categories'] ?? [];
        $visible = array_values(array_filter(RecordCategory::cases(), fn (RecordCategory $category) => ! in_array($category->value, $hidden, true)));

        return $visible ?: RecordCategory::cases();
    }

    public function theme(): Theme
    {
        return Theme::tryFrom((string) $this->setting('theme')) ?? Theme::DEFAULT;
    }

    public function economyFormat(): EconomyFormat
    {
        return EconomyFormat::tryFrom((string) $this->setting('economy_format')) ?? EconomyFormat::Auto;
    }

    /** @return array{urgent_days:int, very_urgent_days:int, urgent_distance:int, very_urgent_distance:int} */
    public function reminderThresholds(): array
    {
        return [
            'urgent_days' => (int) $this->setting('urgent_days'),
            'very_urgent_days' => (int) $this->setting('very_urgent_days'),
            'urgent_distance' => (int) $this->setting('urgent_distance'),
            'very_urgent_distance' => (int) $this->setting('very_urgent_distance'),
        ];
    }
}
