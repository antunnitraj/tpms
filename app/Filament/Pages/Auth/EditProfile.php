<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Schemas\AccountFields;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            AccountFields::name(),
            AccountFields::username(),
            TextInput::make('email')->label(__('Email'))->email()->maxLength(255)
                ->mutateStateForValidationUsing(fn (?string $state) => blank($state) ? null : mb_strtolower(trim($state)))
                ->unique(ignoreRecord: true)
                // Live, so "Current password" appears as soon as the email changes (not after a failed save).
                ->live(debounce: 500),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
