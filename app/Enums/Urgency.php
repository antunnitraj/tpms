<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum Urgency: string implements HasColor, HasIcon, HasLabel
{
    case Done = 'done';
    case Ok = 'ok';
    case Urgent = 'urgent';
    case VeryUrgent = 'very_urgent';
    case PastDue = 'past_due';

    public function getLabel(): string
    {
        return match ($this) {
            self::Done => __('Done'),
            self::Ok => __('Not urgent'),
            self::Urgent => __('Urgent'),
            self::VeryUrgent => __('Very urgent'),
            self::PastDue => __('Past due'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Done => 'gray',
            self::Ok => 'success',
            self::Urgent => 'warning',
            self::VeryUrgent => 'danger',
            self::PastDue => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Done => 'heroicon-o-check',
            self::Ok => 'heroicon-o-clock',
            self::Urgent => 'heroicon-o-bell',
            self::VeryUrgent => 'heroicon-o-bell-alert',
            self::PastDue => 'heroicon-o-exclamation-triangle',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Done => -1,
            self::Ok => 0,
            self::Urgent => 1,
            self::VeryUrgent => 2,
            self::PastDue => 3,
        };
    }

    public function needsAttention(): bool
    {
        return $this->severity() >= self::VeryUrgent->severity();
    }
}
