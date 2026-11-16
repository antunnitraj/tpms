<?php

namespace App\Import\LubeLogger\LiteDb;

use Generator;
use RuntimeException;

/**
 * Read-only reader for LiteDB v5 data files (file format version 8).
 *
 * Instead of walking the indexes it does what LiteDB's own rebuild does:
 * scan every data page, start at each first (non-extend) block, follow the
 * block chain and decode the resulting BSON document. Deleted blocks have a
 * zero position in the slot footer and are skipped.
 */
class LiteDbReader
{
    public const PAGE_SIZE = 8192;

    private const HEADER_INFO = '** This is a LiteDB file **';

    private const HEADER_INFO_OFFSET = 32;

    private const FILE_VERSION_OFFSET = 59;

    private const COLLECTIONS_OFFSET = 192;

    private const PAGE_TYPE_DATA = 4;

    private const NO_PAGE = 0xFFFFFFFF;

    private string $data;

    /** @var array<string, int> collection name => collection page id */
    private array $collections;

    /** @var array<int, list<int>>|null collection page id => data page ids */
    private ?array $dataPages = null;

    public function __construct(string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Database file not found: {$path}");
        }

        $this->data = (string) file_get_contents($path);
        $this->validateHeader();
        $this->collections = BsonReader::decode(substr($this->data, self::COLLECTIONS_OFFSET, self::PAGE_SIZE - self::COLLECTIONS_OFFSET));
    }

    /** @return array<string, int> */
    public function collections(): array
    {
        return $this->collections;
    }

    public function hasCollection(string $name): bool
    {
        return isset($this->collections[$name]);
    }

    /** @return Generator<int, array> */
    public function documents(string $collection): Generator
    {
        if (! isset($this->collections[$collection])) {
            return;
        }

        foreach ($this->dataPagesFor($this->collections[$collection]) as $pageId) {
            $page = $this->page($pageId);
            $highestIndex = ord($page[30]);

            for ($index = 0; $index <= $highestIndex; $index++) {
                [$position] = $this->slot($page, $index);

                // Empty slot, or a continuation block of a document that starts elsewhere.
                if ($position === 0 || ord($page[$position]) !== 0) {
                    continue;
                }

                yield BsonReader::decode($this->readChain($pageId, $index));
            }
        }
    }

    /** @return list<array> */
    public function all(string $collection): array
    {
        return iterator_to_array($this->documents($collection), false);
    }

    public function count(string $collection): int
    {
        $count = 0;
        foreach ($this->documents($collection) as $ignored) {
            $count++;
        }

        return $count;
    }

    private function validateHeader(): void
    {
        if (strlen($this->data) < self::PAGE_SIZE || strlen($this->data) % self::PAGE_SIZE !== 0) {
            throw new RuntimeException('This is not a LiteDB v5 database (unexpected file size). It may be encrypted or damaged.');
        }

        if (substr($this->data, self::HEADER_INFO_OFFSET, strlen(self::HEADER_INFO)) !== self::HEADER_INFO) {
            throw new RuntimeException('This is not a readable LiteDB database. Encrypted LubeLogger databases are not supported.');
        }

        $version = ord($this->data[self::FILE_VERSION_OFFSET]);
        if ($version !== 8) {
            throw new RuntimeException("Unsupported LiteDB file version {$version}; only LiteDB v5 (version 8) is supported.");
        }
    }

    /** @return list<int> */
    private function dataPagesFor(int $collectionPageId): array
    {
        if ($this->dataPages === null) {
            $this->dataPages = [];
            $pages = intdiv(strlen($this->data), self::PAGE_SIZE);

            for ($pageId = 1; $pageId < $pages; $pageId++) {
                $offset = $pageId * self::PAGE_SIZE;

                if (ord($this->data[$offset + 4]) === self::PAGE_TYPE_DATA && ord($this->data[$offset + 23]) > 0) {
                    $colId = unpack('V', $this->data, $offset + 19)[1];
                    $this->dataPages[$colId][] = $pageId;
                }
            }
        }

        return $this->dataPages[$collectionPageId] ?? [];
    }

    private function page(int $pageId): string
    {
        $page = substr($this->data, $pageId * self::PAGE_SIZE, self::PAGE_SIZE);

        if (strlen($page) !== self::PAGE_SIZE) {
            throw new RuntimeException("Page {$pageId} is outside the database file.");
        }

        return $page;
    }

    /** @return array{0: int, 1: int} [position, length] */
    private function slot(string $page, int $index): array
    {
        $address = self::PAGE_SIZE - (($index + 1) * 4);

        return [unpack('v', $page, $address + 2)[1], unpack('v', $page, $address)[1]];
    }

    private function readChain(int $pageId, int $index): string
    {
        $buffer = '';
        $guard = 0;

        while (true) {
            $page = $this->page($pageId);
            [$position, $length] = $this->slot($page, $index);

            if ($position === 0 || $length < 6) {
                throw new RuntimeException("Broken block chain at page {$pageId}, slot {$index}.");
            }

            $buffer .= substr($page, $position + 6, $length - 6);
            $nextPage = unpack('V', $page, $position + 1)[1];

            if ($nextPage === self::NO_PAGE) {
                return $buffer;
            }

            $index = ord($page[$position + 5]);
            $pageId = $nextPage;

            if (++$guard > 100_000) {
                throw new RuntimeException('Block chain loop detected.');
            }
        }
    }
}
