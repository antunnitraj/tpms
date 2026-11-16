<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasLabel;

/**
 * Colour themes a user can pick in Settings: an accent with a grey that suits it
 * (warm stone for warm accents, blue-tinted slate for cool ones). The logo is
 * drawn in the theme's accent, so it always matches.
 */
enum Theme: string implements HasLabel
{
    // A few clearly different accents. Stored values never change; an unknown one falls back to the default.
    case Yellow = 'yellow';
    case Red = 'red';
    case Purple = 'purple';
    case Blue = 'blue';
    case Green = 'green';
    case Graphite = 'graphite';

    public const DEFAULT = self::Yellow;

    public function getLabel(): string
    {
        return match ($this) {
            self::Yellow => __('TPMS yellow'),
            self::Red => __('Racing red'),
            self::Purple => __('Violet night'),
            self::Blue => __('Ocean blue'),
            self::Green => __('Forest green'),
            self::Graphite => __('Graphite'),
        };
    }

    /** @return array<int, string> */
    public function primary(): array
    {
        return match ($this) {
            self::Yellow => Color::Amber,
            self::Red => Color::Red,
            self::Purple => Color::Violet,
            self::Blue => Color::Blue,
            self::Green => Color::Emerald,
            self::Graphite => Color::Zinc,
        };
    }

    /** @return array<int, string> */
    public function gray(): array
    {
        return match ($this) {
            self::Yellow, self::Green => Color::Stone,
            self::Red, self::Graphite => Color::Neutral,
            self::Purple, self::Blue => Color::Slate,
        };
    }

    /** @return array{primary: array<int, string>, gray: array<int, string>} */
    public function colors(): array
    {
        return ['primary' => $this->primary(), 'gray' => $this->gray()];
    }

    /**
     * Bright accents (yellow, orange, teal, green) can't carry white text, so their
     * solid buttons use the light shade with dark text, in dark mode too.
     */
    public function isBright(): bool
    {
        return ! Color::isTextContrastRatioAccessible('#ffffff', $this->primary()[600]);
    }
}
