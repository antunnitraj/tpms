<?php

namespace App\Filament\Resources\Vehicles\Widgets\Media;

use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;

/** "Add photos" / "Add documents": files are only ever added after the ones already there. */
trait AddsVehicleFiles
{
    abstract protected function vehicle(): Vehicle;

    protected function addFilesAction(string $name, string $collection, string $label, string $icon, bool $images, int $maxKilobytes): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->modalHeading($label)
            ->modalDescription(__('They are added after the files you already have.'))
            ->schema(function () use ($images, $maxKilobytes) {
                $upload = FileUpload::make('files')->hiddenLabel()->multiple()->required()
                    ->storeFiles(false)->maxSize($maxKilobytes);

                return [$images ? $upload->image()->panelLayout('grid') : $upload];
            })
            ->modalSubmitActionLabel(__('Add'))
            ->action(function (array $data) use ($collection) {
                foreach ($data['files'] as $file) {
                    $this->vehicle()->addMedia($file)
                        ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                        ->toMediaCollection($collection);
                }

                Notification::make()->success()
                    ->title(trans_choice(':count file added|:count files added', count($data['files']), ['count' => count($data['files'])]))
                    ->send();
            });
    }
}
