<?php

namespace App\Http\Controllers;

use App\Support\Media\FileResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController
{
    public function __invoke(Request $request, string $uuid, ?string $conversion = null): BinaryFileResponse
    {
        $media = Media::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($media->model !== null && Gate::allows('view', $media->model), 403);

        // Conversions are generated on the queue; serve the original until they exist.
        $useConversion = $conversion !== null && $media->hasGeneratedConversion($conversion);
        $path = $useConversion ? $media->getPath($conversion) : $media->getPath();

        abort_unless(is_file($path), 404);

        return FileResponse::make(
            $path,
            $useConversion ? 'image/webp' : $media->mime_type,
            $useConversion ? basename($path) : self::downloadName($media),
            $request->boolean('download'),
            // Thumbnails are kept for a week. The file itself carries its name, which can be
            // renamed at any time: a cached copy would save under the old name.
            $useConversion ? 'private, max-age=604800' : 'private, no-cache',
        );
    }

    /** The name shown in the app plus the file's extension, so a saved file is named the way it is listed. */
    public static function downloadName(Media $media): string
    {
        $name = str_replace(['/', '\\'], '-', trim((string) $media->name)) ?: $media->file_name;
        $extension = pathinfo($media->file_name, PATHINFO_EXTENSION);

        return $extension === '' || Str::endsWith(Str::lower($name), '.'.Str::lower($extension)) ? $name : "{$name}.{$extension}";
    }
}
