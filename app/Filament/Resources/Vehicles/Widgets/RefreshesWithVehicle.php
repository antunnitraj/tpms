<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use Livewire\Attributes\On;

/**
 * Vehicle widgets don't poll: the page tells them after each action (see
 * DispatchesVehicleChanges), so they update as soon as something is saved.
 */
trait RefreshesWithVehicle
{
    protected function getPollingInterval(): ?string
    {
        return null;
    }

    #[On('vehicle-changed')]
    public function refreshWithVehicle(): void {}
}
