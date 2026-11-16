<?php

namespace App\Filament\Resources\Users;

use App\Filament\Schemas\AccountFields;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/** Accounts are created by administrators; there is no public sign-up. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'admin/users';

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            AccountFields::name(),
            AccountFields::username(),
            TextInput::make('email')->label(__('Email'))->email()->maxLength(255)
                ->mutateStateForValidationUsing(fn (?string $state) => blank($state) ? null : mb_strtolower(trim($state)))
                ->unique(ignoreRecord: true),
            TextInput::make('password')->label(__('Password'))->password()->revealable()
                ->rule(Password::min(8))
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? __('Leave empty to keep the current password.') : null),
            Toggle::make('is_admin')->label(__('Administrator'))
                ->helperText(__('Can manage users and imports.'))
                ->disabled(fn (?User $record) => $record?->is(auth()->user())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('vehicles'))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')->weight(FontWeight::SemiBold)->searchable(),
                        TextColumn::make('username')->size(TextSize::Small)->color('gray')->searchable()
                            ->formatStateUsing(fn (User $record) => collect([$record->username, $record->email])->filter()->implode(' · ')),
                    ]),
                    TextColumn::make('vehicles_count')->alignEnd()->grow(false)->color('gray')
                        ->formatStateUsing(fn ($state) => trans_choice(':count vehicle|:count vehicles', $state)),
                    TextColumn::make('is_admin')->grow(false)->badge()->color('primary')->icon('heroicon-m-shield-check')
                        ->state(fn (User $record) => $record->is_admin ? __('Admin') : null),
                ]),
            ])
            ->defaultSort('name')
            ->recordAction('edit')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->slideOver()
                        // Clear the legacy LubeLogger hash when an admin sets a new password.
                        ->mutateDataUsing(fn (array $data) => isset($data['password']) ? $data + ['legacy_sha256' => null] : $data),
                    DeleteAction::make()
                        ->modalHeading(fn (User $record) => __('Delete :name?', ['name' => $record->name]))
                        ->modalDescription(__('Vehicles shared with others stay with them. Vehicles only this user had are moved to you.')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }
}
