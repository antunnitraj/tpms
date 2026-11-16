<?php

namespace App\Http\Controllers;

use App\Models\BackupImport;
use App\Support\Media\FileResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves photos from an extracted backup so admins can see them in the import preview. */
class ImportPreviewFileController
{
    public function __invoke(BackupImport $import, string $path): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);
        abort_unless($import->status === BackupImport::STATUS_PREVIEWED && $import->work_path, 404);
        abort_unless(preg_match('#^(images|documents)/[^/\\\\]+$#', $path) === 1, 404);

        $file = $import->work_path.'/'.$path;
        abort_unless(is_file($file), 404);

        // Files come from someone's backup: never let one open as a page of this app.
        return FileResponse::make($file, mime_content_type($file) ?: null, basename($file), false, 'private, max-age=3600');
    }
}
