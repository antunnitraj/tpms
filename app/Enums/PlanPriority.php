<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PlanPriority: string implements HasColor, HasIcon, HasLabel
{
    case Critical = 'critical';
    case Normal = 'normal';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::Critical => __('Critical'),
            self::Normal => __('Normal'),
            self::Low => __('Low'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::Normal => 'info',
            self::Low => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Critical => 'heroicon-m-chevron-double-up',
            self::Normal => 'heroicon-m-chevron-up',
            self::Low => 'heroicon-m-chevron-down',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }
}
