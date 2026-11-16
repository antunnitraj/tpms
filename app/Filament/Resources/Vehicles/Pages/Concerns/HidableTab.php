<?php

namespace App\Filament\Resources\Vehicles\Pages\Concerns;

use App\Enums\VehicleTab;
use App\Filament\Resources\Vehicles\VehicleResource;

/**
 * Leaves a vehicle tab out of the menu when the user has hidden it in Settings,
 * and sends its address (a bookmark, an old link) to the vehicle's overview.
 */
trait HidableTab
{
    public function mountHidableTab(): void
    {
        if (! static::shouldRegisterNavigation()) {
            $this->redirect(VehicleResource::getUrl('view', ['record' => request()->route('record')]), navigate: true);
        }
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        $tab = VehicleTab::forPage(static::class);

        return $tab === null || (auth()->user()?->showsVehicleTab($tab) ?? true);
    }
}
