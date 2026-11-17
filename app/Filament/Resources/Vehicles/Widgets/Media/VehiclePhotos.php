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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The vehicle's photo gallery: a grid you can drag to reorder (the first photo is
 * the cover), a full-screen viewer (swipe between photos; make cover, edit, rename,
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

    /** What the server can read and write back in the same format (GD). */
    private const EDITABLE = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Set after an edit; not kept between requests, so the viewer opens on it only once. */
    protected ?int $showPhoto = null;

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

    /** Photos the server can crop and turn; others, such as HEIC, are left as they are. */
    public static function canEdit(Media $photo): bool
    {
        return isset(self::EDITABLE[$photo->mime_type]);
    }

    /** The photo the viewer opens on after this request (the one just edited), if any. */
    public function photoToShow(): ?int
    {
        return $this->showPhoto;
    }

    /**
     * Crops, turns and flips a photo the way it was done in the editor (Cropper.js), on the
     * full-size original. The editor shows the smaller preview, so its numbers are scaled up.
     * The edited photo takes the old one's place: same name, position (so cover) and date added.
     *
     * @param  array<string, mixed>  $edit  Cropper.js getData() plus the size of the image it showed.
     */
    public function editPhoto(mixed $media, array $edit): void
    {
        $photo = $this->photo($media);

        $edit = Validator::make($edit, [
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'width' => ['required', 'numeric', 'min:1'],
            'height' => ['required', 'numeric', 'min:1'],
            'rotate' => ['required', 'integer', 'multiple_of:90'],
            'scaleX' => ['required', 'in:1,-1'],
            'scaleY' => ['required', 'in:1,-1'],
            'naturalWidth' => ['required', 'numeric', 'min:1'],
            'naturalHeight' => ['required', 'numeric', 'min:1'],
        ])->validate();

        $extension = self::EDITABLE[$photo->mime_type] ?? null;
        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$extension;

        try {
            throw_if($extension === null, new RuntimeException("Can't edit {$photo->mime_type} photos."));

            // A full-size phone photo takes a few hundred MB while it is turned.
            @ini_set('memory_limit', '1024M');

            // Loading turns the photo upright by its EXIF orientation, as the browser shows it.
            $image = Image::useImageDriver(config('media-library.image_driver'))->loadFile($photo->getPath());
            $scale = $image->getWidth() / $edit['naturalWidth'];
            throw_if(abs($image->getHeight() / $edit['naturalHeight'] - $scale) > $scale * 0.02, new RuntimeException('The editor showed another image.'));

            // Cropper.js flips first, in the photo's own axes, then turns it clockwise.
            if ((int) $edit['scaleX'] === -1) {
                $image->flip(FlipDirection::Horizontal);
            }
            if ((int) $edit['scaleY'] === -1) {
                $image->flip(FlipDirection::Vertical);
            }
            if ($turn = (((int) $edit['rotate'] % 360) + 360) % 360) {
                $image->orientation(Orientation::from($turn));
            }

            $x = min((int) round(max(0, $edit['x']) * $scale), $image->getWidth() - 1);
            $y = min((int) round(max(0, $edit['y']) * $scale), $image->getHeight() - 1);
            $image->manualCrop(max(1, (int) round($edit['width'] * $scale)), max(1, (int) round($edit['height'] * $scale)), $x, $y);

            if ($extension !== 'png') {
                $image->quality(90);
            }
            $image->save($path);

            $edited = $this->vehicle()->addMedia($path)
                ->usingName($photo->name)
                ->usingFileName(pathinfo($photo->file_name, PATHINFO_FILENAME).'.'.$extension)
                ->withCustomProperties($photo->custom_properties)
                ->toMediaCollection('photos');
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('The photo could not be saved'))->body(__('Nothing was changed.'))->send();

            return;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $edited->forceFill(['order_column' => $photo->order_column, 'created_at' => $photo->created_at])->save();
        $photo->delete();
        $this->showPhoto = $edited->getKey();

        Notification::make()->success()->title(__('Photo saved'))->send();
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
