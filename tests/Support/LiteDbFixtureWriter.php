<?php

namespace Tests\Support;

use DateTimeInterface;

/**
 * Writes minimal LiteDB v5 files for tests: a header page with the collection
 * map, one collection page per collection, and data pages with slot footers
 * and chained blocks. Only what the reader needs is filled in.
 */
class LiteDbFixtureWriter
{
    private const PAGE = 8192;

    /** @var array<string, list<string|null>> BSON documents per collection; null = deleted slot */
    private array $collections = [];

    public function add(string $collection, array $document): static
    {
        $this->collections[$collection][] = self::bson($document);

        return $this;
    }

    public function addDeleted(string $collection): static
    {
        $this->collections[$collection][] = null;

        return $this;
    }

    public function write(string $path, int $maxBlockData = 8000): void
    {
        $pages = [];
        $collectionMap = [];
        $nextPageId = 1;

        foreach ($this->collections as $name => $documents) {
            $colId = $nextPageId++;
            $collectionMap[$name] = $colId;
            $pages[$colId] = $this->pageHeader($colId, 2, $colId);

            // Allocate blocks into data pages: [pageId, slotIndex, bytes|null]
            $currentPage = null;
            $free = 0;
            $slots = 0;
            $blocks = [];

            foreach ($documents as $bson) {
                $chunks = $bson === null ? [null] : str_split($bson, $maxBlockData);
                $chain = [];

                foreach ($chunks as $chunk) {
                    $size = $chunk === null ? 0 : 6 + strlen($chunk);

                    if ($currentPage === null || $free < $size + 4) {
                        $currentPage = $nextPageId++;
                        $free = self::PAGE - 32;
                        $slots = 0;
                    }

                    $chain[] = [$currentPage, $slots++, $chunk];
                    $free -= $size + 4;
                }

                $blocks[] = $chain;
            }

            foreach ($blocks as $chain) {
                foreach ($chain as $i => [$pageId, $slot, $chunk]) {
                    $pages[$pageId] ??= ['bytes' => $this->pageHeader($pageId, 4, $colId), 'pos' => 32, 'slots' => []];

                    if ($chunk === null) {
                        $pages[$pageId]['slots'][$slot] = [0, 0];

                        continue;
                    }

                    [$nextPage, $nextSlot] = isset($chain[$i + 1]) ? [$chain[$i + 1][0], $chain[$i + 1][1]] : [0xFFFFFFFF, 0xFF];
                    $block = chr($i > 0 ? 1 : 0).pack('V', $nextPage).chr($nextSlot).$chunk;
                    $pos = $pages[$pageId]['pos'];
                    $pages[$pageId]['bytes'] = substr_replace($pages[$pageId]['bytes'], $block, $pos, strlen($block));
                    $pages[$pageId]['slots'][$slot] = [$pos, strlen($block)];
                    $pages[$pageId]['pos'] += strlen($block);
                }
            }
        }

        $header = $this->pageHeader(0, 1, 0);
        $header = substr_replace($header, '** This is a LiteDB file **', 32, 27);
        $header[59] = chr(8);
        $map = self::bson($collectionMap);
        $header = substr_replace($header, $map, 192, strlen($map));

        $file = $header;
        for ($id = 1; $id < $nextPageId; $id++) {
            $page = $pages[$id] ?? $this->pageHeader($id, 0, 0);

            if (is_array($page)) {
                $bytes = $page['bytes'];
                $highest = max(array_keys($page['slots']));
                $bytes[23] = chr(count(array_filter($page['slots'], fn ($s) => $s[0] !== 0)));
                $bytes[30] = chr($highest);
                foreach ($page['slots'] as $index => [$position, $length]) {
                    $address = self::PAGE - (($index + 1) * 4);
                    $bytes = substr_replace($bytes, pack('v', $length).pack('v', $position), $address, 4);
                }
                $page = $bytes;
            }

            $file .= $page;
        }

        file_put_contents($path, $file);
    }

    private function pageHeader(int $pageId, int $type, int $colId): string
    {
        $page = str_repeat("\0", self::PAGE);
        $page = substr_replace($page, pack('V', $pageId), 0, 4);
        $page[4] = chr($type);
        $page = substr_replace($page, pack('V', $colId), 19, 4);

        return $page;
    }

    public static function bson(array $document): string
    {
        $body = '';

        foreach ($document as $key => $value) {
            $key = (string) $key;

            [$type, $bytes] = match (true) {
                $value === null => [0x0A, ''],
                is_bool($value) => [0x08, chr($value ? 1 : 0)],
                is_int($value) && $value >= -2147483648 && $value <= 2147483647 => [0x10, pack('l', $value)],
                is_int($value) => [0x12, pack('q', $value)],
                is_float($value) => [0x01, pack('e', $value)],
                is_string($value) => [0x02, pack('l', strlen($value) + 1).$value."\0"],
                $value instanceof NetDecimal => [0x13, $value->bytes()],
                $value instanceof DateTimeInterface => [0x09, pack('q', (int) $value->format('Uv'))],
                is_array($value) && array_is_list($value) => [0x04, self::bson($value)],
                is_array($value) => [0x03, self::bson($value)],
            };

            $body .= chr($type).$key."\0".$bytes;
        }

        return pack('l', strlen($body) + 5).$body."\0";
    }
}
