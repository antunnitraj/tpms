<?php

namespace App\Filament\Resources\Vehicles\Widgets\Media;

use App\Filament\Resources\Vehicles\Widgets\ChecksVehicleAccess;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The vehicle's photo gallery: a grid you can drag to reorder (the first photo is
 * the cover), a full-screen viewer (swipe between photos; make cover, rename,
 * download, delete) and Select to delete several at once.
 */
class VehiclePhotos extends Widget implements HasActions, HasSchemas
{
    use AddsVehicleFiles, ChecksVehicleAccess, InteractsWithActions, InteractsWithSchemas;

    protected string $view = 'filament.vehicles.media.photos';

    public ?Model $record = null;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function vehicle(): Vehicle
    {
        return $this->record;
    }

    /** Read from the database on every render, so a new cover or name shows straight away. @return Collection<int, Media> */
    public function photos(): Collection
    {
        return $this->query()->orderBy('order_column')->get();
    }

    /** Saves the order after a drag. Only this vehicle's photos count; any left out keep their place at the end. */
    public function reorderPhotos(array $ids): void
    {
        $own = $this->query()->orderBy('order_column')->pluck('id');
        $order = collect($ids)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $own->contains($id))->unique()->values();

        Media::setNewOrder($order->merge($own->diff($order))->values()->all());
    }

    public function addPhotosAction(): Action
    {
        return $this->addFilesAction('addPhotos', 'photos', __('Add photos'), 'heroicon-o-camera', images: true, maxKilobytes: 30 * 1024);
    }

    public function makeCoverAction(): Action
    {
        return Action::make('makeCover')
            ->action(function (array $arguments) {
                $cover = $this->photo($arguments['media'] ?? null);
                $order = $this->query()->orderBy('order_column')->pluck('id')
                    ->reject(fn ($id) => $id === $cover->getKey())->prepend($cover->getKey());
                Media::setNewOrder($order->all());

                Notification::make()->success()->title(__('Cover photo changed'))->send();
            });
    }

    public function renamePhotoAction(): Action
    {
        return Action::make('renamePhoto')
            ->modalHeading(__('Rename photo'))
            ->modalWidth('md')
            ->fillForm(fn (array $arguments) => ['name' => $this->photo($arguments['media'] ?? null)->name])
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(255)])
            ->action(fn (array $data, array $arguments) => $this->photo($arguments['media'] ?? null)->update(['name' => $data['name']]))
            ->modalSubmitActionLabel(__('Rename'))
            ->successNotificationTitle(__('Renamed'));
    }

    public function deletePhotoAction(): Action
    {
        return Action::make('deletePhoto')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete this photo?'))
            ->modalDescription(__('It is deleted for good.'))
            ->action(fn (array $arguments) => $this->photo($arguments['media'] ?? null)->delete())
            ->successNotificationTitle(__('Deleted'));
    }

    public function deletePhotosAction(): Action
    {
        return Action::make('deletePhotos')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => trans_choice('Delete :count photo?|Delete :count photos?', count($arguments['ids'] ?? []), ['count' => count($arguments['ids'] ?? [])]))
            ->modalDescription(__('They are deleted for good.'))
            ->action(function (array $arguments) {
                // Only this vehicle's photos can be in the selection; anything else is ignored.
                $photos = $this->query()->whereKey($arguments['ids'] ?? [])->get();
                $photos->each->delete();

                Notification::make()->success()
                    ->title(trans_choice(':count photo deleted|:count photos deleted', $photos->count(), ['count' => $photos->count()]))
                    ->send();
            });
    }

    private function query(): Builder
    {
        return Media::query()
            ->where('model_type', 'vehicle')
            ->where('model_id', $this->vehicle()->getKey())
            ->where('collection_name', 'photos');
    }

    /** One of this vehicle's photos; anything else is "not found". */
    private function photo(mixed $id): Media
    {
        return $this->query()->findOrFail($id);
    }
}
