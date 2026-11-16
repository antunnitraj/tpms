<?php

namespace App\Support;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Vehicles\VehicleResource;

/** Items of the bottom tab bar shown on phones. */
class TabBar
{
    /** @return list<array{label: string, icon: string, active_icon: string, url: string, active: bool}> */
    public static function items(): array
    {
        $items = [[
            'label' => __('Garage'),
            'icon' => 'tpms-garage',
            'active_icon' => 'tpms-garage-solid',
            'url' => VehicleResource::getUrl(),
            'active' => request()->routeIs('filament.app.resources.vehicles.*'),
        ]];

        $items[] = [
            'label' => __('Settings'),
            'icon' => 'heroicon-o-cog-6-tooth',
            'active_icon' => 'heroicon-s-cog-6-tooth',
            'url' => Settings::getUrl(),
            'active' => request()->routeIs('filament.app.pages.settings', 'filament.app.auth.profile'),
        ];

        return $items;
    }

    public static function shouldShow(): bool
    {
        return auth()->check()
            && ! request()->routeIs('filament.app.auth.login', 'filament.app.setup', 'filament.app.auth.password-reset.*');
    }
}
