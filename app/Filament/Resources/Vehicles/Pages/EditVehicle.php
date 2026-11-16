<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Actions\ShareVehicleAction;
use App\Filament\Resources\Vehicles\Pages\Concerns\VehiclePageHeading;
use App\Filament\Resources\Vehicles\VehicleResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;

class EditVehicle extends EditRecord
{
    use VehiclePageHeading;

    protected static string $resource = VehicleResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function getNavigationLabel(): string
    {
        return __('Vehicle settings');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ShareVehicleAction::make(),
                VehicleResource::deleteAction(),
            ])
                ->label(__('More'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->size('lg')
                ->tooltip(__('More')),
        ];
    }

    /** Under the fields, not under the section titles. */
    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getRedirectUrl(): ?string
    {
        return VehicleResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
