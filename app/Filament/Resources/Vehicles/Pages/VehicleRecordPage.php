<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\Pages\Concerns\HidableTab;
use App\Filament\Resources\Vehicles\Pages\Concerns\VehiclePageHeading;
use App\Filament\Resources\Vehicles\VehicleResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** Base for custom (non-table-of-a-relation) vehicle pages. */
abstract class VehicleRecordPage extends Page
{
    use HidableTab, InteractsWithRecord, VehiclePageHeading {
        VehiclePageHeading::getBreadcrumbs insteadof InteractsWithRecord;
    }

    protected static string $resource = VehicleResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record !== null && (bool) auth()->user()?->can('view', $record);
    }

    public function getWidgetData(): array
    {
        return ['record' => $this->getRecord()];
    }
}
