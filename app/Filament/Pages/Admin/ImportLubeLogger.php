<?php

namespace App\Filament\Pages\Admin;

use App\Import\LubeLogger\LubeLoggerImporter;
use App\Models\BackupImport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

/**
 * Upload a LubeLogger backup, review exactly what will be imported (vehicles,
 * users, counts, warnings), adjust how it is read, then confirm.
 */
class ImportLubeLogger extends Page
{
    protected string $view = 'filament.pages.admin.import-lube-logger';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'admin/import';

    public ?int $importId = null;

    // Not "upload": that name collides with Livewire's $wire.upload() in the browser.
    public ?array $source = [];

    public ?array $options = [];

    public static function getNavigationLabel(): string
    {
        return __('Import from LubeLogger');
    }

    public function getTitle(): string
    {
        return __('Import from LubeLogger');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->uploadForm->fill();

        // Pick up an unfinished preview (e.g. after a reload or switching apps on a phone).
        $pending = BackupImport::query()
            ->where('user_id', auth()->id())
            ->where('status', BackupImport::STATUS_PREVIEWED)
            ->where('updated_at', '>=', now()->subDay())
            ->latest()
            ->first();

        if ($pending?->work_path && is_dir($pending->work_path)) {
            $this->importId = $pending->getKey();
            $this->optionsForm->fill($pending->options);
        }
    }

    public function getImport(): ?BackupImport
    {
        return $this->importId ? BackupImport::query()->find($this->importId) : null;
    }

    public function uploadForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('source')
            ->components([
                FileUpload::make('backup')
                    ->label(__('LubeLogger backup (.zip)'))
                    ->helperText(__('In LubeLogger: Settings → Backups → Make Backup.'))
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'multipart/x-zip'])
                    ->storeFiles(false)
                    ->maxSize(2 * 1024 * 1024)
                    ->validationMessages(['mimetypes' => __('This isn\'t a ZIP file. Choose the backup ZIP that LubeLogger made.')])
                    ->required(),
            ]);
    }

    public function optionsForm(Schema $schema): Schema
    {
        $preview = $this->getImport()?->preview ?? [];
        $refresh = fn () => $this->refreshPreview();
        $users = User::query()->orderBy('username')->pluck('username', 'id')->all();

        return $schema
            ->statePath('options')
            ->components([
                Section::make(__('How to read the backup'))
                    ->description(__('Detected automatically. Change these if the dates in the preview look wrong.'))
                    ->columns(['md' => 3])
                    ->schema([
                        Select::make('timezone')->label(__('LubeLogger server timezone'))
                            ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()->required()->live()->afterStateUpdated($refresh),
                        Select::make('date_order')->label(__('Date format in vehicle details'))
                            ->options([
                                'dmy' => __('Day, month, year (31.12.2025)'),
                                'mdy' => __('Month, day, year (12/31/2025)'),
                                'ymd' => __('Year, month, day (2025-12-31)'),
                            ])->required()->live()->afterStateUpdated($refresh),
                        Select::make('target_user_id')->label(__('LubeLogger root user becomes'))
                            ->helperText(__('Gets access to every imported vehicle.'))
                            ->options($users)->required()->live()->afterStateUpdated($refresh),
                        // Right next to the date format, so a wrong guess is easy to spot.
                        View::make('filament.pages.admin.import-date-check')
                            ->viewData(['samples' => $preview['detected']['date_samples'] ?? []])
                            ->visible(filled($preview['detected']['date_samples'] ?? []))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('LubeLogger users'))
                    ->description(__('New accounts keep their LubeLogger password. Users that match an account here are linked to it.'))
                    ->visible(filled($preview['users'] ?? []))
                    ->schema(collect($preview['users'] ?? [])->map(fn (array $user) => Grid::make(['default' => 1, 'sm' => 2])
                        ->extraAttributes(['class' => 'border-t border-gray-100 pt-4 first:border-t-0 first:pt-0 dark:border-white/5'])
                        ->schema([
                            View::make('filament.pages.admin.import-user')->viewData(['user' => $user])->columnSpanFull(),
                            Select::make("users.{$user['ref']}.action")->hiddenLabel()
                                ->options(['create' => __('Create a new account'), 'map' => __('Use an existing account'), 'skip' => __('Don\'t import')])
                                ->required()->live()->afterStateUpdated($refresh),
                            Select::make("users.{$user['ref']}.user_id")->hiddenLabel()->placeholder(__('Choose account'))
                                ->validationAttribute(__('account'))
                                ->options($users)
                                ->visible(fn (Get $get) => $get("users.{$user['ref']}.action") === 'map')
                                ->required(fn (Get $get) => $get("users.{$user['ref']}.action") === 'map')
                                ->live()->afterStateUpdated($refresh),
                        ]))->all()),
            ]);
    }

    public function vehicleCount(): int
    {
        return count($this->getImport()?->preview['vehicles'] ?? []);
    }

    /** Each vehicle card has its own import switch and unit pickers (plain inputs bound to options.vehicles.*). */
    public function updatedOptions(mixed $value, string $key): void
    {
        if (str_starts_with($key, 'vehicles.')) {
            $this->refreshPreview();
        }
    }

    public function analyze(): void
    {
        $data = $this->uploadForm->getState();

        try {
            [$path, $name] = $this->resolveUpload($data);
            $import = app(LubeLoggerImporter::class)->analyze($path, $name, auth()->user());
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('Could not read the backup'))->body($e->getMessage())->persistent()->send();

            return;
        } finally {
            // The ZIP (up to 2 GB) isn't needed once it's unpacked or rejected. Clear the
            // field too, so pressing the button again doesn't look for the deleted file.
            if (isset($path) && is_file($path)) {
                @unlink($path);
            }

            $this->uploadForm->fill();
        }

        $this->importId = $import->getKey();
        $this->optionsForm->fill($import->options);
        $this->source = [];
    }

    public function refreshPreview(): void
    {
        $import = $this->getImport();
        if ($import === null) {
            return;
        }

        $options = array_replace_recursive($import->options, $this->options);

        try {
            app(LubeLoggerImporter::class)->preview($import, $options);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('Could not update the preview'))->body($e->getMessage())->send();
        }
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label(fn () => $this->vehicleCount() ? trans_choice('Import :count vehicle|Import :count vehicles', $this->vehicleCount(), ['count' => $this->vehicleCount()]) : __('Switch on a vehicle to import'))
            ->icon('heroicon-o-check')
            ->disabled(fn () => $this->vehicleCount() === 0)
            ->requiresConfirmation()
            ->modalHeading(__('Import this backup?'))
            ->modalDescription(fn () => ($previous = $this->previousImport())
                ? __('This backup was already imported on :date. Importing again creates duplicates — consider undoing the earlier import first.', ['date' => $previous->imported_at?->toFormattedDateString()])
                : __('Everything shown in the preview will be added. You can undo the import afterwards.'))
            ->action(function (Action $action) {
                try {
                    $this->optionsForm->validate();
                } catch (ValidationException $e) {
                    // Close the confirmation, so the message under the field can be seen.
                    Notification::make()->danger()->title(__('Something is missing'))->body(collect($e->errors())->flatten()->first())->send();
                    $this->setErrorBag($e->validator->errors());
                    $action->cancel();
                }

                $import = $this->getImport();

                try {
                    app(LubeLoggerImporter::class)->import($import);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('Import failed — nothing was imported'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('Import complete'))->send();
            });
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Start over'))
            ->color('gray')
            ->action(function () {
                if ($import = $this->getImport()) {
                    app(LubeLoggerImporter::class)->discard($import);
                }

                $this->reset('importId', 'options');
                $this->uploadForm->fill();
            });
    }

    public function undoAction(): Action
    {
        return Action::make('undo')
            ->label(__('Undo import'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('All vehicles, entries, files and accounts created by this import will be deleted.'))
            ->action(function (array $arguments) {
                $import = BackupImport::query()->findOrFail($arguments['import'] ?? $this->importId);
                app(LubeLoggerImporter::class)->undo($import);

                Notification::make()->success()->title(__('Import undone'))->send();
            });
    }

    public function previousImport(): ?BackupImport
    {
        $import = $this->getImport();

        return $import ? app(LubeLoggerImporter::class)->previouslyImported($import) : null;
    }

    /** @return array{0: string, 1: string} */
    private function resolveUpload(array $data): array
    {
        $file = $data['backup'] ?? null;
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }

        if ($file instanceof TemporaryUploadedFile) {
            return [$file->getRealPath(), $file->getClientOriginalName()];
        }

        throw new \RuntimeException(__('Choose a backup to import.'));
    }
}
