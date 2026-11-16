<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\Widgets\Media\VehicleDocuments;
use App\Filament\Resources\Vehicles\Widgets\Media\VehiclePhotos;

/**
 * Photos and documents of the vehicle, each as its own list: add, reorder,
 * select several to delete. (Files attached to entries stay with their entry.)
 */
class VehicleMedia extends VehicleRecordPage
{
    protected string $view = 'filament.vehicles.media';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    public static function getNavigationLabel(): string
    {
        return __('Photos & files');
    }

    protected function getHeaderWidgets(): array
    {
        return [VehiclePhotos::class, VehicleDocuments::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
