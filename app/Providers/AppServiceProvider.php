<?php

namespace App\Providers;

use App\Auth\TpmsUserProvider;
use App\Models\AppSetting;
use App\Models\BackupImport;
use App\Models\Equipment;
use App\Models\FuelRecord;
use App\Models\Note;
use App\Models\OdometerReading;
use App\Models\Plan;
use App\Models\Record;
use App\Models\Reminder;
use App\Models\Supply;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\AdminOnlyPolicy;
use App\Policies\VehicleEntryPolicy;
use App\Policies\VehiclePolicy;
use BladeUI\Icons\Factory as IconFactory;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Our own icons (e.g. "tpms-fuel") next to Heroicons.
        $this->callAfterResolving(IconFactory::class, fn (IconFactory $icons) => $icons->add('tpms', [
            'path' => resource_path('svg'),
            'prefix' => 'tpms',
        ]));
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'user' => User::class,
            'vehicle' => Vehicle::class,
            'record' => Record::class,
            'fuel_record' => FuelRecord::class,
            'odometer_reading' => OdometerReading::class,
            'reminder' => Reminder::class,
            'plan' => Plan::class,
            'supply' => Supply::class,
            'note' => Note::class,
            'equipment' => Equipment::class,
        ]);

        Auth::provider('tpms', fn ($app, array $config) => new TpmsUserProvider($app['hash'], $config['model']));

        Gate::policy(Vehicle::class, VehiclePolicy::class);
        foreach ([Record::class, FuelRecord::class, OdometerReading::class, Reminder::class, Plan::class, Note::class, Equipment::class, Supply::class] as $model) {
            Gate::policy($model, VehicleEntryPolicy::class);
        }
        Gate::policy(User::class, AdminOnlyPolicy::class);
        Gate::policy(BackupImport::class, AdminOnlyPolicy::class);

        $this->applyAppSettings();
    }

    /** Currency and display timezone are chosen in the setup wizard. */
    private function applyAppSettings(): void
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return;
            }

            Number::useCurrency(AppSetting::currency());
            FilamentTimezone::set((string) AppSetting::get('timezone', config('app.timezone')));
        } catch (Throwable) {
            // Database not reachable yet (e.g. during image build); defaults apply.
        }
    }
}
