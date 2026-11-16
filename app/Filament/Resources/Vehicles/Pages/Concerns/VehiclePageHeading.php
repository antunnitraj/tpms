<?php

namespace App\Filament\Resources\Vehicles\Pages\Concerns;

use App\Models\Vehicle;
use App\Services\Odometer;
use App\Support\Format;

/** Vehicle pages show the vehicle as heading and the section in the tab title. */
trait VehiclePageHeading
{
    public function vehicle(): Vehicle
    {
        return $this->getRecord();
    }

    public function getHeading(): string
    {
        return $this->vehicle()->display_name;
    }

    public function getSubheading(): ?string
    {
        $vehicle = $this->vehicle();
        $odometer = Odometer::current($vehicle);

        return collect([
            $vehicle->nickname ? $vehicle->full_name : null,
            $vehicle->license_plate,
            $odometer !== null ? Format::distance($odometer, $vehicle->distanceUnit()) : null,
            $vehicle->isSold() ? __('Sold :date', ['date' => $vehicle->sold_date->toFormattedDateString()]) : null,
        ])->filter()->implode(' · ') ?: null;
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel().' · '.$this->vehicle()->display_name;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
