<?php

namespace App\Filament\Pages\Admin;

use App\Models\AppSetting;
use App\Services\InstanceReset;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/** Instance-wide settings, and a full reset back to first-run setup. */
class AppSettings extends Page
{
    protected string $view = 'filament.pages.admin.app-settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'admin/settings';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Instance settings');
    }

    public function getTitle(): string
    {
        return __('Instance settings');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill([
            'currency' => AppSetting::currency(),
            'timezone' => AppSetting::get('timezone', config('app.timezone')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(['sm' => 2])->components([
            TextInput::make('currency')->label(__('Currency'))->required()->length(3)->alpha()->helperText(__('ISO code, e.g. EUR, USD, GBP.')),
            Select::make('timezone')->label(__('Timezone'))->required()->searchable()
                ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->helperText(__('Decides when "today" starts for due dates.')),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        AppSetting::put('currency', strtoupper($data['currency']));
        AppSetting::put('timezone', $data['timezone']);

        Notification::make()->success()->title(__('Saved'))->send();
    }

    /** Deletes everything, the signed-in admin included, and starts over at Setup. */
    public function resetAction(): Action
    {
        return Action::make('reset')
            ->label(__('Reset everything'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading(__('Reset The Pocket Mechanic completely?'))
            ->modalDescription(__('Every user (you too), vehicle, entry, photo, document and setting is deleted for good. The Pocket Mechanic then starts again at Setup. There is no undo, so make a backup first if you might need anything.'))
            ->schema([
                TextInput::make('confirmation')->label(__('Type RESET to confirm'))
                    ->required()->in(['RESET'])->validationMessages(['in' => __('Type RESET in capital letters.')])
                    ->autocomplete('off'),
                TextInput::make('password')->label(__('Your password'))
                    ->password()->revealable()->required()->currentPassword(),
            ])
            ->modalSubmitActionLabel(__('Delete everything'))
            ->action(function () {
                // Sign out first, while the account still exists.
                auth()->logout();
                session()->invalidate();
                session()->regenerateToken();

                InstanceReset::run();

                $this->redirect(route('filament.app.setup'), navigate: false);
            });
    }
}
