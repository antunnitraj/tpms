<?php

namespace App\Filament\Resources\Vehicles\Widgets\Media;

use App\Filament\Resources\Vehicles\Widgets\ChecksVehicleAccess;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ViewColumn;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A list of one vehicle's files on its Photos & files tab. Files are only ever
 * added (never replaced as a set), and removed one by one or a selection at a time.
 */
abstract class VehicleMediaTable extends TableWidget
{
    use AddsVehicleFiles, ChecksVehicleAccess;

    public ?Model $record = null;

    protected static bool $isDiscovered = false;

    // Shown with the page instead of a moment later: these lists are the page.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function vehicle(): Vehicle
    {
        return $this->record;
    }

    /** Media of the vehicle itself in one collection (photos or documents). */
    protected function vehicleMedia(string $collection): Builder
    {
        return Media::query()
            ->where('model_type', 'vehicle')
            ->where('model_id', $this->vehicle()->getKey())
            ->where('collection_name', $collection);
    }

    /** A small preview of the file: the picture itself, or an icon for its type. */
    protected function thumbColumn(): ViewColumn
    {
        return ViewColumn::make('thumb')->view('filament.vehicles.media.file-thumb')->grow(false);
    }

    protected function renameAction(): Action
    {
        return Action::make('rename')
            ->label(__('Rename'))
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(__('Rename file'))
            ->modalWidth('md')
            ->fillForm(fn (Media $record) => ['name' => $record->name])
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(255)])
            ->action(fn (Media $record, array $data) => $record->update(['name' => $data['name']]))
            ->modalSubmitActionLabel(__('Rename'))
            ->successNotificationTitle(__('Renamed'));
    }

    protected function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('Download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->url(fn (Media $record) => $record->getUrl().(str_contains($record->getUrl(), '?') ? '&' : '?').'download=1');
    }

    protected function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('Delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Media $record) => __('Delete “:name”?', ['name' => $record->name]))
            ->modalDescription(__('The file is deleted for good.'))
            ->action(fn (Media $record) => $record->delete())
            ->successNotificationTitle(__('Deleted'));
    }

    /** Rows come from this vehicle's own media query, so a selection can only hold its files. */
    protected function deleteSelectedAction(): BulkAction
    {
        return BulkAction::make('deleteSelected')
            ->label(__('Delete selected'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete the selected files?'))
            ->modalDescription(__('They are deleted for good.'))
            ->action(function (Collection $records) {
                $records->each->delete();

                Notification::make()->success()
                    ->title(trans_choice(':count file deleted|:count files deleted', $records->count(), ['count' => $records->count()]))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
