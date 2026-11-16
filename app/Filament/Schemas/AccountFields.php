<?php

namespace App\Filament\Schemas;

use App\Models\User;
use App\Support\Usernames;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Name and username fields. For a new account, typing a name fills in the username,
 * unless the username was changed by hand. An existing username is never changed
 * this way: it's what that person signs in with.
 */
class AccountFields
{
    public static function name(): TextInput
    {
        return TextInput::make('name')
            ->label(__('Name'))
            ->required()
            ->maxLength(255)
            ->live(debounce: 400)
            ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set, ?User $record) {
                if ($record?->exists) {
                    return;
                }

                $username = (string) $get('username');

                // Only follow the name while the username is empty or still the one we generated.
                if ($username !== '' && $username !== Usernames::slug($old) && $username !== Usernames::suggest($old, $record?->getKey())) {
                    return;
                }

                $set('username', filled($state) ? Usernames::suggest($state, $record?->getKey()) : '');
            });
    }

    public static function username(): TextInput
    {
        return TextInput::make('username')
            ->label(__('Username'))
            ->required()
            ->maxLength(50)
            ->alphaDash()
            // Stored in lower case (see User), so "Ana" is taken when "ana" exists.
            ->mutateStateForValidationUsing(fn (?string $state) => $state === null ? null : mb_strtolower(trim($state)))
            ->unique(table: User::class, column: 'username', ignoreRecord: true)
            ->autocomplete('username')
            ->helperText(fn (?User $record) => $record?->exists ? null : __('Filled in from the name; you can change it.'));
    }
}
