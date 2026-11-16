<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;

/** Everyone with access can add or remove other people (but not themselves). */
class ShareVehicleAction
{
    public static function make(): Action
    {
        return Action::make('share')
            ->label(__('Share'))
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->slideOver()
            ->modalHeading(__('Share vehicle'))
            ->modalSubmitActionLabel(__('Save'))
            ->modalDescription(__('People you share with can see and change everything about this vehicle.'))
            ->fillForm(fn (Vehicle $record) => [
                'collaborators' => $record->users()->whereKeyNot(auth()->id())->pluck('users.id')->all(),
            ])
            ->schema(fn (Vehicle $record) => [
                CheckboxList::make('collaborators')
                    ->label(__('People with access'))
                    ->options($record->users()->whereKeyNot(auth()->id())->orderBy('username')->get()
                        ->mapWithKeys(fn (User $u) => [$u->getKey() => "{$u->name} ({$u->username})"]))
                    ->helperText(__('Uncheck someone to remove their access.'))
                    ->visible(fn () => $record->users()->whereKeyNot(auth()->id())->exists()),
                TagsInput::make('add')
                    ->label(__('Add people'))
                    ->placeholder(__('Username or email'))
                    ->splitKeys(['Tab', ',', ' ']),
            ])
            ->action(function (array $data, Vehicle $record) {
                $keep = collect($data['collaborators'] ?? [])->map(fn ($id) => (int) $id);
                $missing = [];

                foreach ($data['add'] ?? [] as $login) {
                    $user = User::query()
                        ->whereRaw('lower(username) = ?', [mb_strtolower($login)])
                        ->orWhereRaw('lower(email) = ?', [mb_strtolower($login)])
                        ->first();

                    $user ? $keep->push($user->getKey()) : $missing[] = $login;
                }

                $record->users()->sync($keep->push(auth()->id())->unique()->all());

                if ($missing) {
                    // The others were added: say so, so nobody adds them twice.
                    Notification::make()->warning()
                        ->title(__('Not found: :users', ['users' => implode(', ', $missing)]))
                        ->body(__('Ask an administrator to create their account first.').(count($missing) < count($data['add'] ?? []) ? ' '.__('Everyone else was added.') : ''))
                        ->send();
                } else {
                    Notification::make()->success()->title(__('Sharing updated'))->send();
                }
            });
    }
}
