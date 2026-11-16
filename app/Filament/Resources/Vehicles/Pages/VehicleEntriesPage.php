<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\Pages\Concerns\DispatchesVehicleChanges;
use App\Filament\Resources\Vehicles\Pages\Concerns\HidableTab;
use App\Filament\Resources\Vehicles\Pages\Concerns\VehiclePageHeading;
use App\Filament\Resources\Vehicles\VehicleResource;
use Filament\Resources\Pages\ManageRelatedRecords;

/** Base for the per-vehicle section pages (log, fuel, reminders, …). */
abstract class VehicleEntriesPage extends ManageRelatedRecords
{
    use DispatchesVehicleChanges, HidableTab, VehiclePageHeading;

    protected static string $resource = VehicleResource::class;

    public function getWidgetData(): array
    {
        return ['record' => $this->getRecord()];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /** An empty table because of a search or a filter isn't "nothing here yet". */
    protected function emptyHeading(string $heading): string
    {
        return $this->isTableNarrowed() ? __('Nothing matches') : $heading;
    }

    protected function emptyDescription(string $description): string
    {
        return $this->isTableNarrowed() ? __('Try another search, or clear the filters.') : $description;
    }

    private function isTableNarrowed(): bool
    {
        return filled($this->getTableSearch()) || $this->getTable()->getActiveFiltersCount() > 0;
    }
}
