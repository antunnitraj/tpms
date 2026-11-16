<?php

namespace App\Import\LubeLogger;

use App\Models\BackupImport;
use App\Models\Supply;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Import flow: analyze (extract + detect options + preview) → optionally
 * re-preview with changed options → import → (undo).
 */
class LubeLoggerImporter
{
    public function analyze(string $zipPath, string $filename, User $actor): BackupImport
    {
        $workPath = storage_path('app/private/imports/'.Str::uuid());

        try {
            $archive = BackupArchive::extract($zipPath, $workPath);
            $archive->reader();
        } catch (Throwable $e) {
            File::deleteDirectory($workPath);

            throw $e;
        }

        $import = BackupImport::query()->create([
            'user_id' => $actor->getKey(),
            'filename' => $filename,
            'sha256' => hash_file('sha256', $zipPath),
            'status' => BackupImport::STATUS_PREVIEWED,
            'work_path' => $workPath,
            'options' => (new Analyzer($archive))->defaultOptions($actor),
        ]);

        return $this->preview($import, $import->options);
    }

    /** Re-run the dry run with new options and store the resulting preview. */
    public function preview(BackupImport $import, array $options): BackupImport
    {
        $this->ensurePreviewed($import);
        $archive = $this->archive($import);
        $mapped = (new Mapper($archive, $options))->map();

        $import->update([
            'options' => $options,
            'preview' => (new PreviewBuilder($archive))->build($mapped, $options),
            'warnings' => $mapped['warnings'],
        ]);

        return $import;
    }

    public function import(BackupImport $import): BackupImport
    {
        $this->ensurePreviewed($import);
        $archive = $this->archive($import);
        $mapped = (new Mapper($archive, $import->options))->map();

        try {
            $result = (new Persister($import, $mapped))->persist();
        } catch (Throwable $e) {
            $import->update(['error' => $e->getMessage()]);

            throw $e;
        }

        $import->update([
            'status' => BackupImport::STATUS_IMPORTED,
            'summary' => $result,
            'warnings' => array_values(array_unique([...$mapped['warnings'], ...$result['warnings']])),
            'imported_at' => now(),
            'error' => null,
        ]);

        $archive->delete();
        $import->update(['work_path' => null]);

        return $import;
    }

    /** Remove everything an import created, including copied files. */
    public function undo(BackupImport $import): void
    {
        if ($import->status !== BackupImport::STATUS_IMPORTED) {
            throw new RuntimeException(__('Only completed imports can be undone.'));
        }

        $users = User::query()->where('import_id', $import->getKey())->pluck('id');
        if ($users->contains(auth()->id())) {
            throw new RuntimeException(__('This import created the account you are signed in with. Undo it from another administrator account.'));
        }
        if ($users->isNotEmpty() && ! User::query()->where('is_admin', true)->whereKeyNot($users->all())->exists()) {
            throw new RuntimeException(__('Undoing this import would remove every administrator. Make another account an administrator first.'));
        }

        DB::transaction(function () use ($import) {
            $id = $import->getKey();

            Vehicle::query()->where('import_id', $id)->lazyById()->each(fn (Vehicle $v) => $v->delete());
            Supply::query()->where('import_id', $id)->lazyById()->each(fn (Supply $s) => $s->delete());
            User::query()->where('import_id', $id)->lazyById()->each(fn (User $u) => $u->delete());

            $import->update(['status' => BackupImport::STATUS_UNDONE, 'undone_at' => now()]);
        });
    }

    public function discard(BackupImport $import): void
    {
        if ($import->work_path) {
            File::deleteDirectory($import->work_path);
        }

        if ($import->status === BackupImport::STATUS_PREVIEWED) {
            $import->delete();
        }
    }

    /** Previews nobody confirmed within a day. */
    public function cleanupStale(): int
    {
        $stale = BackupImport::query()
            ->where('status', BackupImport::STATUS_PREVIEWED)
            ->where('updated_at', '<', now()->subDay())
            ->get();

        $stale->each(fn (BackupImport $import) => $this->discard($import));

        // Folders of an analysis that died half-way (timeout, out of memory) have no import row.
        $root = storage_path('app/private/imports');
        $known = BackupImport::query()->whereNotNull('work_path')->pluck('work_path')->all();
        foreach (is_dir($root) ? File::directories($root) : [] as $folder) {
            if (! in_array($folder, $known, true) && filemtime($folder) < now()->subDay()->getTimestamp()) {
                File::deleteDirectory($folder);
            }
        }

        return $stale->count();
    }

    public function archive(BackupImport $import): BackupArchive
    {
        if (! $import->work_path || ! is_dir($import->work_path)) {
            throw new RuntimeException(__('The uploaded backup has expired. Please upload it again.'));
        }

        return new BackupArchive($import->work_path);
    }

    public function previouslyImported(BackupImport $import): ?BackupImport
    {
        return BackupImport::query()
            ->where('sha256', $import->sha256)
            ->where('status', BackupImport::STATUS_IMPORTED)
            ->whereKeyNot($import->getKey())
            ->latest('imported_at')
            ->first();
    }

    private function ensurePreviewed(BackupImport $import): void
    {
        if ($import->status !== BackupImport::STATUS_PREVIEWED) {
            throw new RuntimeException(__('This import has already been processed.'));
        }
    }
}
