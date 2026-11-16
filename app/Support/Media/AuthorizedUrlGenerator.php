<?php

namespace App\Support\Media;

use DateTimeInterface;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * Media lives on a private disk. Every URL points at the media route, which
 * checks that the viewer may see the model the file belongs to.
 */
class AuthorizedUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        $conversion = $this->conversion?->getName();

        // A file never changes, so its URL only has to change once: when a thumbnail
        // made on the queue replaces the original that was served in the meantime.
        // (Not on every save, or reordering photos would re-download them all.)
        return route('media.show', array_filter([
            'uuid' => $this->media->uuid,
            'conversion' => $conversion,
            'v' => $conversion !== null && $this->media->hasGeneratedConversion($conversion) ? 1 : null,
        ]));
    }

    public function getTemporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        return $this->getUrl();
    }

    public function getResponsiveImagesDirectoryUrl(): string
    {
        return route('media.show', ['uuid' => $this->media->uuid]);
    }
}
