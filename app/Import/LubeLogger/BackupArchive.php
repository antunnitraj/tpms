<?php

namespace App\Import\LubeLogger;

use App\Import\LubeLogger\LiteDb\LiteDbReader;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * An extracted LubeLogger backup ZIP. Only the parts TPMS uses are extracted:
 * the database, config, and the images and documents the database refers to
 * (LubeLogger leaves deleted entries' uploads behind). Paths are validated
 * against zip-slip.
 */
class BackupArchive
{
    public const DATABASE = 'data/cartracker.db';

    private const MANIFEST = 'tpms-archive.json';

    private const MAX_ENTRIES = 100_000;

    private const MAX_UNCOMPRESSED_BYTES = 8 * 1024 * 1024 * 1024;

    private ?LiteDbReader $reader = null;

    /** @var array<string, list<array>> */
    private array $collections = [];

    public function __construct(public readonly string $root) {}

    public static function extract(string $zipPath, string $destination): self
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('The file is not a valid ZIP archive.'));
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException(__('The backup contains too many files.'));
            }

            File::ensureDirectoryExists($destination);
            $total = 0;
            $hasDatabase = false;
            $media = [];

            // Database and config first; images and documents once we know which are used.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = self::normalize($stat['name']);

                if ($name === null || ! self::wanted($name)) {
                    continue;
                }

                if (self::isMedia($name)) {
                    $media[$name] = $stat;

                    continue;
                }

                self::extractEntry($zip, $stat, $destination.'/'.$name, $total);
                $hasDatabase = $hasDatabase || $name === self::DATABASE;
            }

            if (! $hasDatabase) {
                File::deleteDirectory($destination);

                throw new RuntimeException(__('This does not look like a LubeLogger backup: data/cartracker.db is missing. Backups from instances that store data in PostgreSQL contain no data; export them with LubeLogger\'s migration tool first.'));
            }

            $archive = new self($destination);
            $used = array_intersect_key($media, $archive->referencedFiles());

            foreach ($used as $name => $stat) {
                self::extractEntry($zip, $stat, $destination.'/'.$name, $total);
            }

            File::put($destination.'/'.self::MANIFEST, json_encode([
                'files' => count($media),
                'unused_files' => count($media) - count($used),
            ]));
        } finally {
            $zip->close();
        }

        return $archive;
    }

    private static function extractEntry(ZipArchive $zip, array $stat, string $target, int &$total): void
    {
        $total += $stat['size'];
        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException(__('The backup is too large to import.'));
        }

        File::ensureDirectoryExists(dirname($target));

        $in = $zip->getStream($stat['name']);
        $out = fopen($target, 'wb');
        $written = 0;

        try {
            // The sizes in a ZIP's directory can lie (zip bombs): count what really comes out.
            while (! feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $written += strlen($chunk);
                if ($written > $stat['size'] || $total - $stat['size'] + $written > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException(__('The backup is too large to import.'));
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private static function isMedia(string $name): bool
    {
        return preg_match('#^(images|documents)/[^/]+$#', $name) === 1;
    }

    /**
     * Every "/images/…" or "/documents/…" location stored anywhere in the database
     * (vehicle photos, entry attachments).
     *
     * @return array<string, true> keyed by archive path, e.g. "images/abc.jpg"
     */
    public function referencedFiles(): array
    {
        $found = [];
        $walk = function (mixed $value) use (&$walk, &$found): void {
            if (is_array($value)) {
                array_walk($value, $walk);
            } elseif (is_string($value) && preg_match('#^/?(images|documents)/([^/\\\\]+)$#', $value, $m)) {
                $found[$m[1].'/'.$m[2]] = true;
            }
        };

        foreach (array_keys($this->reader()->collections()) as $collection) {
            $walk($this->collection($collection));
        }

        return $found;
    }

    /** Images and documents in the backup that nothing refers to, so they were left out. */
    public function unusedFiles(): int
    {
        return $this->json(self::MANIFEST)['unused_files'] ?? 0;
    }

    /** Images and documents in the backup, used or not. */
    public function filesInBackup(): int
    {
        return $this->json(self::MANIFEST)['files'] ?? 0;
    }

    /** Normalised relative path, or null when the entry is unsafe or a directory. */
    private static function normalize(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);

        if ($name === '' || str_ends_with($name, '/') || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name)) {
            return null;
        }

        $parts = array_filter(explode('/', $name), fn ($p) => $p !== '' && $p !== '.');

        if (in_array('..', $parts, true)) {
            return null;
        }

        return implode('/', $parts);
    }

    private static function wanted(string $name): bool
    {
        return $name === self::DATABASE
            || $name === 'config/userConfig.json'
            || $name === 'config/serverConfig.json'
            || self::isMedia($name);
    }

    public function reader(): LiteDbReader
    {
        return $this->reader ??= new LiteDbReader($this->root.'/'.self::DATABASE);
    }

    /** @return list<array> */
    public function collection(string $name): array
    {
        return $this->collections[$name] ??= $this->reader()->all($name);
    }

    public function count(string $name): int
    {
        return count($this->collection($name));
    }

    /** Absolute path of a stored file location like "/documents/x.pdf", or null if not in the backup. */
    public function file(string $location): ?string
    {
        if (! preg_match('#^/?(images|documents)/([^/\\\\]+)$#', $location, $m)) {
            return null;
        }

        $path = $this->root.'/'.$m[1].'/'.$m[2];

        return is_file($path) ? $path : null;
    }

    public function userConfig(): array
    {
        return $this->json('config/userConfig.json');
    }

    public function serverConfig(): array
    {
        return $this->json('config/serverConfig.json');
    }

    private function json(string $relative): array
    {
        $path = $this->root.'/'.$relative;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function delete(): void
    {
        File::deleteDirectory($this->root);
    }
}
