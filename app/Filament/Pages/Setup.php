<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Admin\ImportLubeLogger;
use App\Filament\Schemas\AccountFields;
use App\Http\Middleware\EnsureSetupComplete;
use App\Models\AppSetting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** First-run wizard: create the administrator, then offer a LubeLogger import. */
class Setup extends SimplePage
{
    protected string $view = 'filament.pages.setup';

    public ?array $data = [];

    public bool $created = false;

    public function mount(): void
    {
        $this->form->fill([
            'currency' => config('tpms.currency'),
            'timezone' => config('app.timezone'),
        ]);
    }

    public function getTitle(): string
    {
        return __('Welcome to The Pocket Mechanic');
    }

    public function getHeading(): string
    {
        return $this->created ? __('You\'re all set') : __('Welcome to The Pocket Mechanic');
    }

    public function getSubheading(): ?string
    {
        return $this->created
            ? __('Do you want to bring over your vehicles from LubeLogger?')
            : __('Create the administrator account to get started.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                AccountFields::name()->autofocus(),
                AccountFields::username(),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255)
                    ->helperText(__('Optional. You can sign in with username or email.')),
                TextInput::make('password')->label(__('Password'))->password()->revealable()->required()
                    ->rule(Password::min(8))->autocomplete('new-password')->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')->label(__('Confirm password'))->password()->revealable()
                    ->required()->dehydrated(false),
                Section::make(__('Preferences'))
                    ->compact()
                    ->collapsed()
                    ->columns(['sm' => 2])
                    ->schema([
                        TextInput::make('currency')->label(__('Currency'))->required()->length(3)->alpha()
                            ->dehydrateStateUsing(fn (string $state) => strtoupper($state))
                            ->helperText(__('ISO code, e.g. EUR, USD, GBP.')),
                        Select::make('timezone')->label(__('Timezone'))->required()->searchable()
                            ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
                    ]),
                Actions::make([
                    Action::make('create')->label(__('Create administrator'))->submit('create'),
                ])->fullWidth(),
            ]);
    }

    public function create(): void
    {
        // A second submit (double tap, another tab or browser) after the admin exists.
        if (EnsureSetupComplete::isComplete()) {
            $this->setupAlreadyDone();

            return;
        }

        $data = $this->form->getState();

        $user = DB::transaction(function () use ($data) {
            // Serialise concurrent submits; only the very first account may be created here.
            DB::statement('LOCK TABLE users IN EXCLUSIVE MODE');
            if (User::query()->exists()) {
                return null;
            }

            AppSetting::put('currency', $data['currency']);
            AppSetting::put('timezone', $data['timezone']);

            return User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?: null,
                'password' => Hash::make($data['password']),
                'is_admin' => true,
            ]);
        });

        if ($user === null) {
            $this->setupAlreadyDone();

            return;
        }

        Filament::auth()->login($user, remember: true);
        session()->regenerate();

        $this->created = true;
    }

    /** The admin who just finished carries on; anyone else goes to sign in. */
    private function setupAlreadyDone(): void
    {
        if (Filament::auth()->check()) {
            $this->created = true;

            return;
        }

        $this->redirect(Filament::getLoginUrl(), navigate: false);
    }

    /** The browser's timezone, when it is a valid one (sent once from the setup page). */
    public function useBrowserTimezone(string $timezone): void
    {
        if (! $this->created && in_array($timezone, timezone_identifiers_list(), true)) {
            $this->data['timezone'] = $timezone;
        }
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label(__('Import a LubeLogger backup'))
            ->icon('heroicon-o-arrow-up-tray')
            ->url(ImportLubeLogger::getUrl());
    }

    public function skipAction(): Action
    {
        return Action::make('skip')
            ->label(__('Start fresh'))
            ->color('gray')
            ->url(Filament::getUrl());
    }

    /** The welcome heading is enough here; the sign-in page shows the logo. */
    public function hasLogo(): bool
    {
        return false;
    }
}
