<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PlanStatus: string implements HasColor, HasIcon, HasLabel
{
    case Planned = 'planned';
    case Doing = 'doing';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::Doing => __('Doing'),
            self::Done => __('Done'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Doing => 'warning',
            self::Done => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Planned => 'heroicon-o-inbox-stack',
            self::Doing => 'heroicon-o-play',
            self::Done => 'heroicon-o-check-circle',
        };
    }
}
