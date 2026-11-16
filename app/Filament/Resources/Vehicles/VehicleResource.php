<?php

namespace App\Filament\Resources\Vehicles;

use App\Filament\Schemas\VehicleForm;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = 'tpms-garage';

    protected static ?int $navigationSort = -10;

    protected static ?string $recordTitleAttribute = 'display_name';

    // A side menu on desktop (twelve tabs don't fit in a row); phones get Filament's dropdown either way.
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function getNavigationLabel(): string
    {
        return __('Garage');
    }

    public static function getModelLabel(): string
    {
        return __('vehicle');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->accessibleBy(auth()->user());
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['make', 'model', 'nickname', 'license_plate'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->display_name;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([__('Plate') => $record->license_plate]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(VehicleForm::components(settingsPage: true));
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            Pages\ViewVehicle::class,
            Pages\ManageVehicleLog::class,
            Pages\ManageVehicleFuel::class,
            Pages\VehicleOdometer::class,
            Pages\ManageVehicleReminders::class,
            Pages\ManageVehiclePlans::class,
            Pages\ManageVehicleSupplies::class,
            Pages\ManageVehicleNotes::class,
            Pages\ManageVehicleEquipment::class,
            Pages\VehicleMedia::class,
            Pages\VehicleReports::class,
            Pages\EditVehicle::class,
        ]);
    }

    /** Deleting a vehicle takes everything with it, for everyone it's shared with: say so. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Vehicle $record) => __('Delete :vehicle?', ['vehicle' => $record->display_name]))
            ->modalDescription(__('All of its log entries, fuel-ups, reminders, plans, notes, photos and files are deleted too, also for everyone it\'s shared with. This can\'t be undone.'))
            ->successRedirectUrl(static::getUrl());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'view' => Pages\ViewVehicle::route('/{record}'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
            'log' => Pages\ManageVehicleLog::route('/{record}/log'),
            'fuel' => Pages\ManageVehicleFuel::route('/{record}/fuel'),
            'odometer' => Pages\VehicleOdometer::route('/{record}/odometer'),
            'reminders' => Pages\ManageVehicleReminders::route('/{record}/reminders'),
            'plans' => Pages\ManageVehiclePlans::route('/{record}/plans'),
            'supplies' => Pages\ManageVehicleSupplies::route('/{record}/supplies'),
            'notes' => Pages\ManageVehicleNotes::route('/{record}/notes'),
            'equipment' => Pages\ManageVehicleEquipment::route('/{record}/equipment'),
            'media' => Pages\VehicleMedia::route('/{record}/media'),
            'reports' => Pages\VehicleReports::route('/{record}/reports'),
        ];
    }
}
