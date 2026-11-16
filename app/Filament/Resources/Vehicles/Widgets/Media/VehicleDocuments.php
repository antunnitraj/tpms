<?php

namespace App\Filament\Resources\Vehicles\Widgets\Media;

use Filament\Actions\ActionGroup;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** The vehicle's own documents (registration, insurance…): reorder, open, select several to delete. */
class VehicleDocuments extends VehicleMediaTable
{
    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Documents'))
            ->description(__('Registration, insurance, manuals, receipts…'))
            ->query(fn () => $this->vehicleMedia('documents'))
            ->defaultSort('order_column')
            ->reorderable('order_column')
            ->paginated(false)
            ->extraAttributes(['class' => 'tpms-selectable'])
            ->columns([
                Split::make([
                    $this->thumbColumn(),
                    Stack::make([
                        TextColumn::make('name')->weight(FontWeight::SemiBold)->wrap()->extraAttributes(['class' => 'wrap-anywhere'])->searchable(),
                        TextColumn::make('size')->size(TextSize::Small)->color('gray')
                            ->formatStateUsing(fn (Media $record) => Number::fileSize($record->size).' · '.$record->created_at?->toFormattedDateString()),
                    ])->space(1),
                ]),
            ])
            ->recordUrl(fn (Media $record) => $record->getUrl(), shouldOpenInNewTab: true)
            ->recordActions([
                ActionGroup::make([$this->renameAction(), $this->downloadAction(), $this->deleteAction()]),
            ])
            ->headerActions([
                $this->addFilesAction('addDocuments', 'documents', __('Add documents'), 'heroicon-o-document-plus', images: false, maxKilobytes: 100 * 1024)->color('gray'),
            ])
            ->toolbarActions([$this->deleteSelectedAction()])
            ->emptyStateHeading(__('No documents yet'))
            ->emptyStateIcon('heroicon-o-document');
    }
}
