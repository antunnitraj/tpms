<?php

namespace App\Filament\Resources\Vehicles\Pages\Concerns;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/** Tells the page's widgets (stats, charts) to refresh after an action has run. */
trait DispatchesVehicleChanges
{
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        // Tab counts (log types, plan lanes) were read before the action ran: count again,
        // and rebuild the page content the tabs are part of.
        if (property_exists($this, 'cachedTabs')) {
            unset($this->cachedTabs);
            $this->cacheSchema('content');
        }

        // Not after deleting the vehicle (Filament has dropped the record then): the page is on its way to the garage.
        if ($this->record instanceof Model && $this->record->exists) {
            $this->dispatch('vehicle-changed');
        }
    }
}
