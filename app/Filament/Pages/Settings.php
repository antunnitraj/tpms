<?php

namespace App\Filament\Pages;

use App\Enums\EconomyFormat;
use App\Enums\RecordCategory;
use App\Enums\Theme;
use App\Enums\VehicleTab;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Personal preferences: colour theme, which vehicle tabs show, how fuel economy is shown, when reminders turn urgent. */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 50;

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    public function mount(): void
    {
        $user = auth()->user();

        $this->form->fill([
            'vehicle_tabs' => collect(VehicleTab::cases())->filter(fn (VehicleTab $tab) => $user->showsVehicleTab($tab))->pluck('value')->all(),
            'record_categories' => array_column($user->recordCategories(), 'value'),
            'economy_format' => $user->setting('economy_format'),
            ...$user->reminderThresholds(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Vehicle tabs'))
                    ->description(__('What each vehicle shows. Overview and Vehicle settings are always there.'))
                    ->schema([
                        CheckboxList::make('vehicle_tabs')->hiddenLabel()
                            ->options(collect(VehicleTab::cases())->mapWithKeys(fn (VehicleTab $tab) => [$tab->value => $tab->getLabel()]))
                            ->columns(['default' => 2, 'sm' => 3])
                            ->bulkToggleable()
                            ->live(),
                        CheckboxList::make('record_categories')->label(__('Log entry types'))
                            ->options(collect(RecordCategory::cases())->mapWithKeys(fn (RecordCategory $category) => [$category->value => $category->getLabel()]))
                            ->required()
                            ->validationMessages(['required' => __('Keep at least one type of log entry.')])
                            ->visible(fn (Get $get) => in_array(VehicleTab::Log->value, $get('vehicle_tabs') ?? [], true)),
                    ]),
                Section::make(__('Fuel economy'))
                    ->schema([
                        Select::make('economy_format')->label(__('Show fuel economy as'))->options(EconomyFormat::class)->required()->native(false)->selectablePlaceholder(false),
                    ]),
                // Short labels so both columns stay aligned on phones.
                Section::make(__('Reminders'))
                    ->description(__('How early a reminder turns urgent and very urgent.'))
                    ->schema([
                        Fieldset::make(__('Time before it\'s due'))
                            ->columns(['default' => 2])
                            ->schema([
                                TextInput::make('urgent_days')->label(__('Urgent'))->validationAttribute(__('urgent days'))
                                    ->integer()->minValue(1)->inputMode('numeric')->suffix(__('days'))->required(),
                                TextInput::make('very_urgent_days')->label(__('Very urgent'))->validationAttribute(__('very urgent days'))
                                    ->integer()->minValue(1)->inputMode('numeric')->suffix(__('days'))->required()->lte('urgent_days'),
                            ]),
                        Fieldset::make(__('Distance before it\'s due'))
                            ->columns(['default' => 2])
                            ->schema([
                                TextInput::make('urgent_distance')->label(__('Urgent'))->validationAttribute(__('urgent distance'))
                                    ->integer()->minValue(1)->inputMode('numeric')->required(),
                                TextInput::make('very_urgent_distance')->label(__('Very urgent'))->validationAttribute(__('very urgent distance'))
                                    ->integer()->minValue(1)->inputMode('numeric')->required()->lte('urgent_distance'),
                                Text::make(__('In each vehicle\'s own unit (km, mi or hours).'))->color('gray')->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /** Themes apply at once: saved, then the page reloads so every colour switches over. */
    public function setTheme(string $theme): void
    {
        $theme = Theme::from($theme);
        $user = auth()->user();
        $user->update(['settings' => ['theme' => $theme->value] + ($user->settings ?? [])]);

        $this->redirect(static::getUrl(), navigate: false);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        $settings = array_replace_recursive($user->settings ?? [], [
            'economy_format' => $data['economy_format'] instanceof EconomyFormat ? $data['economy_format']->value : $data['economy_format'],
            'urgent_days' => (int) $data['urgent_days'],
            'very_urgent_days' => (int) $data['very_urgent_days'],
            'urgent_distance' => (int) $data['urgent_distance'],
            'very_urgent_distance' => (int) $data['very_urgent_distance'],
        ]);

        // Stored as what's hidden, so tabs added later show up by default. Lists are set whole, not merged.
        $settings['hidden_vehicle_tabs'] = array_values(array_diff(array_column(VehicleTab::cases(), 'value'), $data['vehicle_tabs'] ?? []));
        if (array_key_exists('record_categories', $data)) {
            $settings['hidden_record_categories'] = array_values(array_diff(array_column(RecordCategory::cases(), 'value'), $data['record_categories']));
        }

        $user->update(['settings' => $settings]);

        Notification::make()->success()->title(__('Settings saved'))->send();
    }
}
