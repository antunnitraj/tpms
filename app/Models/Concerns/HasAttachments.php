<?php

namespace App\Models\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Files attached to an entry live in the "attachments" media collection;
 * URLs and links to other entries are kept in the `links` column.
 */
trait HasAttachments
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        // The private media disk, also for uploads from entry forms (see Vehicle).
        $this->addMediaCollection('attachments')->useDisk('media');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 320, 320)
            ->format('webp')
            ->performOnCollections('attachments');
    }
}
