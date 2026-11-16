<?php

namespace App\Import\LubeLogger\LiteDb;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Decodes LiteDB's BSON variant. Differences from MongoDB BSON that matter:
 * 0x13 holds a .NET System.Decimal (four int32: lo, mid, hi, flags) instead of
 * IEEE Decimal128, and dates are milliseconds since the Unix epoch in UTC.
 *
 * Decimals are returned as exact strings, dates as CarbonImmutable (UTC),
 * binary values as raw strings (GUIDs as canonical strings).
 */
class BsonReader
{
    /** .NET DateTime.MinValue / MaxValue in Unix milliseconds. */
    private const MIN_DATE_MS = -62135596800000;

    private const MAX_DATE_MS = 253402300799999;

    public static function decode(string $bytes): array
    {
        $offset = 0;

        return (new self)->document($bytes, $offset);
    }

    private function document(string $bytes, int &$offset): array
    {
        $length = $this->int32($bytes, $offset);
        $end = $offset + $length - 1;

        if ($length < 5 || $end >= strlen($bytes) + 1) {
            throw new RuntimeException("Invalid BSON document length {$length} at offset {$offset}.");
        }

        $offset += 4;
        $document = [];

        while ($offset < $end) {
            $type = ord($bytes[$offset++]);
            $nameEnd = strpos($bytes, "\0", $offset);
            if ($nameEnd === false || $nameEnd >= $end) {
                throw new RuntimeException("Invalid BSON element name at offset {$offset}.");
            }
            $name = substr($bytes, $offset, $nameEnd - $offset);
            $offset = $nameEnd + 1;
            $document[$name] = $this->value($bytes, $offset, $type);

            // A damaged file must not send the reader backwards or past the document.
            if ($offset > $end) {
                throw new RuntimeException("BSON element \"{$name}\" runs past its document.");
            }
        }

        $offset = $end + 1;

        return $document;
    }

    private function value(string $bytes, int &$offset, int $type): mixed
    {
        switch ($type) {
            case 0x01: // double
                $value = unpack('e', $bytes, $offset)[1];
                $offset += 8;

                return $value;
            case 0x02: // string
                $length = $this->checkedLength($bytes, $offset, 4, 1);
                $value = substr($bytes, $offset + 4, $length - 1);
                $offset += 4 + $length;

                return $value;
            case 0x03: // document
                return $this->document($bytes, $offset);
            case 0x04: // array
                return array_values($this->document($bytes, $offset));
            case 0x05: // binary
                $length = $this->checkedLength($bytes, $offset, 5, 0);
                $subtype = ord($bytes[$offset + 4]);
                $data = substr($bytes, $offset + 5, $length);
                $offset += 5 + $length;

                return $subtype === 0x04 && $length === 16 ? $this->guid($data) : $data;
            case 0x07: // ObjectId
                $value = bin2hex(substr($bytes, $offset, 12));
                $offset += 12;

                return $value;
            case 0x08: // boolean
                return ord($bytes[$offset++]) !== 0;
            case 0x09: // UTC datetime (ms)
                $ms = unpack('q', $bytes, $offset)[1];
                $offset += 8;

                return $this->date($ms);
            case 0x0A: // null
                return null;
            case 0x10: // int32
                $value = $this->int32($bytes, $offset);
                $offset += 4;

                return $value;
            case 0x12: // int64
                $value = unpack('q', $bytes, $offset)[1];
                $offset += 8;

                return $value;
            case 0x13: // .NET decimal
                $value = $this->decimal(substr($bytes, $offset, 16));
                $offset += 16;

                return $value;
            case 0xFF: // MinValue
            case 0x7F: // MaxValue
                return null;
            default:
                throw new RuntimeException(sprintf('Unsupported BSON type 0x%02X at offset %d.', $type, $offset));
        }
    }

    /** A string/binary length that is not negative and stays inside the data. */
    private function checkedLength(string $bytes, int $offset, int $header, int $minimum): int
    {
        $length = $this->int32($bytes, $offset);

        if ($length < $minimum || $offset + $header + $length > strlen($bytes)) {
            throw new RuntimeException("Invalid BSON length {$length} at offset {$offset}.");
        }

        return $length;
    }

    private function int32(string $bytes, int $offset): int
    {
        return unpack('l', $bytes, $offset)[1];
    }

    private function date(int $ms): ?CarbonImmutable
    {
        if ($ms <= self::MIN_DATE_MS || $ms >= self::MAX_DATE_MS) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    /** System.Decimal: 96-bit integer (lo, mid, hi) scaled by 10^scale, sign in bit 31 of flags. */
    private function decimal(string $data): string
    {
        [, $lo, $mid, $hi, $flags] = unpack('V4', $data);
        $scale = ($flags >> 16) & 0xFF;
        $negative = ($flags & 0x80000000) !== 0;

        $integer = bcadd(bcadd(bcmul((string) $hi, '18446744073709551616'), bcmul((string) $mid, '4294967296')), (string) $lo);

        if ($scale > 0) {
            $integer = str_pad($integer, $scale + 1, '0', STR_PAD_LEFT);
            $integer = substr($integer, 0, -$scale).'.'.substr($integer, -$scale);
        }

        if ($negative && trim($integer, '0.') !== '') {
            $integer = '-'.$integer;
        }

        return $integer;
    }

    /** .NET GUID byte order: first three groups little-endian. */
    private function guid(string $data): string
    {
        $hex = bin2hex($data);

        return implode('-', [
            implode('', array_reverse(str_split(substr($hex, 0, 8), 2))),
            implode('', array_reverse(str_split(substr($hex, 8, 4), 2))),
            implode('', array_reverse(str_split(substr($hex, 12, 4), 2))),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
