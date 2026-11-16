<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Schemas\NoteForm;
use App\Models\Note;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageVehicleNotes extends VehicleEntriesPage
{
    protected static string $relationship = 'notes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('Notes');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add note'))->modalHeading(__('Add note'))->icon('heroicon-o-plus')->labeledFrom('sm')->slideOver()
                ->model(Note::class)
                ->schema(fn () => NoteForm::components($this->vehicle()))
                ->using(fn (array $data) => $this->vehicle()->notes()->create($data))
                ->createAnother(false),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(NoteForm::components($this->vehicle()))->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('title')->weight(FontWeight::SemiBold)->searchable()->wrap(),
                        TextColumn::make('body')->size(TextSize::Small)->color('gray')->limit(140)->searchable()
                            // A plain-text preview: render the markdown, then drop the markup.
                            ->formatStateUsing(fn ($state) => str($state)->markdown(['html_input' => 'strip'])->stripTags()->squish()),
                    ])->space(1),
                    // Only pinned notes get an icon (false would show Filament's "no" icon).
                    IconColumn::make('pinned')->grow(false)->getStateUsing(fn (Note $record) => $record->pinned ?: null)
                        ->icon('heroicon-s-map-pin')->color('primary'),
                ]),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('pinned')->orderBy('title'))
            ->recordAction('edit')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->slideOver(),
                    Action::make('pin')->label(fn (Note $record) => $record->pinned ? __('Unpin') : __('Pin'))
                        ->icon('heroicon-o-map-pin')
                        ->action(fn (Note $record) => $record->update(['pinned' => ! $record->pinned])),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn () => $this->emptyHeading(__('No notes')))
            ->emptyStateDescription(fn () => $this->emptyDescription(__('Keep tyre sizes, part numbers or anything else handy. Pinned notes show on the overview.')))
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
