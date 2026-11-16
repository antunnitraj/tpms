<?php

namespace Tests\Support;

/** A .NET System.Decimal value for LiteDB fixtures. */
class NetDecimal
{
    public function __construct(public string $value) {}

    public function bytes(): string
    {
        $negative = str_starts_with($this->value, '-');
        $digits = ltrim($this->value, '-');
        $scale = str_contains($digits, '.') ? strlen(substr($digits, strpos($digits, '.') + 1)) : 0;
        $integer = ltrim(str_replace('.', '', $digits), '0') ?: '0';

        $hi = bcdiv($integer, '18446744073709551616', 0);
        $rest = bcmod($integer, '18446744073709551616');
        $mid = bcdiv($rest, '4294967296', 0);
        $lo = bcmod($rest, '4294967296');

        $flags = ($scale << 16) | ($negative ? 0x80000000 : 0);

        return pack('V4', (int) $lo, (int) $mid, (int) $hi, $flags);
    }
}
