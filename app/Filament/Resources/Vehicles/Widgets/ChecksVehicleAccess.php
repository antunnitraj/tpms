<?php

namespace App\Filament\Resources\Vehicles\Widgets;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Gate;

/**
 * Widgets are Livewire components of their own: the page's access check doesn't
 * cover their later requests. Check on every request, so someone removed from a
 * vehicle can't keep using a page they still had open.
 */
trait ChecksVehicleAccess
{
    public function bootedChecksVehicleAccess(): void
    {
        if ($this->record instanceof Vehicle && Gate::allows('view', $this->record)) {
            return;
        }

        // Deleted from the page this widget is on: the widget is updated in the same request,
        // after the delete. There's nothing left to show, and the page is going to the garage.
        if ($this->record instanceof Vehicle && ! Vehicle::query()->whereKey($this->record->getKey())->exists()) {
            $this->skipRender();

            return;
        }

        abort(403);
    }
}
