<?php

namespace App\Filament\Schemas;

use App\Models\Vehicle;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class NoteForm
{
    public static function components(Vehicle $vehicle): array
    {
        return [
            TextInput::make('title')->label(__('Title'))->required()->maxLength(255),
            MarkdownEditor::make('body')->label(__('Note'))
                ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']]),
            Toggle::make('pinned')->label(__('Pin to the vehicle overview')),
            Fields::attachments(),
            Fields::tags($vehicle, 'notes'),
        ];
    }
}
