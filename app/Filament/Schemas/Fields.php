<?php

namespace App\Filament\Schemas;

use App\Models\Supply;
use App\Models\SupplyMovement;
use App\Models\Vehicle;
use App\Services\Odometer;
use App\Services\Supplies;
use App\Support\Clock;
use App\Support\Format;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;

/** Reusable, mobile-friendly form fields shared by all entry forms. */
class Fields
{
    /** Typos like "0020" would otherwise make reports loop over two thousand years. */
    public const EARLIEST_DATE = '1900-01-01';

    /** Fits the database columns: decimal(14,3) money, integer odometer. */
    private const MAX_MONEY = 99_999_999_999;

    private const MAX_MILEAGE = 99_999_999;

    public static function date(string $name = 'date', ?string $label = null): DatePicker
    {
        return DatePicker::make($name)
            ->label($label ?? __('Date'))
            ->required()
            ->default(fn () => Clock::today()->toDateString())
            ->minDate(self::EARLIEST_DATE)
            ->maxDate(fn () => Clock::today()->addYear()->toDateString());
    }

    public static function mileage(Vehicle $vehicle, string $name = 'mileage', bool $required = false): TextInput
    {
        $current = Odometer::current($vehicle);

        return TextInput::make($name)
            ->label(__('Odometer'))
            ->integer()
            ->minValue(0)
            ->maxValue(self::MAX_MILEAGE)
            ->inputMode('numeric')
            ->suffix($vehicle->distanceUnit()->short())
            ->required($required)
            ->placeholder($current !== null ? number_format($current) : null)
            ->helperText($current !== null ? __('Last known: :mileage', ['mileage' => Format::distance($current, $vehicle->distanceUnit())]) : null)
            ->default($current);
    }

    public static function money(string $name, ?string $label = null, ?int $till = null): TextInput
    {
        return static::decimal(TextInput::make($name)
            ->label($label ?? __('Cost'))
            ->minValue(-self::MAX_MONEY)
            ->maxValue(self::MAX_MONEY)
            ->prefix(Format::currencySymbol()), $till);
    }

    /**
     * A decimal number typed on a phone. It is a text field with the decimal keypad, because a number
     * field refuses the comma that many keyboards offer instead of a dot; a comma counts as the decimal
     * point. With $till, digits fill in from the right like a till: 1234 becomes 12.34 ($till = 2).
     * (Not ->numeric(): Filament would turn "12,34" into 12.) The typing side is x-tpms-decimal, in pwa/head.
     */
    public static function decimal(TextInput $input, ?int $till = null): TextInput
    {
        $input->rule('numeric')
            ->inputMode('decimal')
            ->extraAlpineAttributes(['x-tpms-decimal' => (string) $till])
            ->mutateStateForValidationUsing(fn (mixed $state) => static::normalizeDecimal($state))
            ->mutateDehydratedStateUsing(fn (mixed $state) => static::normalizeDecimal($state));

        if ($till !== null) {
            $input->placeholder(number_format(0, $till))
                ->formatStateUsing(fn (mixed $state) => static::withDecimals($state, $till));
        }

        return $input;
    }

    /** "12,5" → "12.5", and nothing typed → null. */
    public static function normalizeDecimal(mixed $state): mixed
    {
        if (! is_string($state)) {
            return $state;
        }

        $state = str_replace(',', '.', trim($state));

        return $state === '' ? null : $state;
    }

    /** A number with as many decimals as a till field types ("40.000" → "40.00"), unless that would drop digits. */
    public static function withDecimals(mixed $value, int $places): mixed
    {
        $value = static::normalizeDecimal(is_int($value) || is_float($value) ? (string) $value : $value);

        if (! is_numeric($value)) {
            return $value;
        }

        $fixed = number_format((float) $value, $places, '.', '');

        return abs((float) $fixed - (float) $value) < 1e-9 ? $fixed : (string) $value;
    }

    public static function notes(string $name = 'notes'): Textarea
    {
        return Textarea::make($name)->label(__('Notes'))->rows(3)->autosize();
    }

    public static function tags(?Vehicle $vehicle = null, string $table = 'records'): TagsInput
    {
        return TagsInput::make('tags')
            ->label(__('Tags'))
            ->suggestions(fn () => $vehicle ? static::existingTags($vehicle, $table) : [])
            ->splitKeys(['Tab', ',']);
    }

    /** @return list<string> */
    public static function existingTags(Vehicle $vehicle, string $table): array
    {
        return DB::table($table)
            ->where('vehicle_id', $vehicle->getKey())
            ->whereNotNull('tags')
            ->selectRaw('distinct jsonb_array_elements_text(tags) as tag')
            ->orderBy('tag')
            ->limit(100)
            ->pluck('tag')
            ->all();
    }

    public static function attachments(string $collection = 'attachments'): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($collection)
            ->label(__('Attachments'))
            ->collection($collection)
            ->multiple()
            ->reorderable()
            ->appendFiles()
            ->preserveFilenames()
            ->downloadable()
            ->openable()
            ->previewable()
            ->panelLayout('grid')
            ->visibility('private')
            ->maxSize(100 * 1024);
    }

    /** External links plus links to other entries (the latter come from imports and are read-only). */
    public static function links(): Repeater
    {
        return Repeater::make('links')
            ->label(__('Links'))
            ->schema([
                TextInput::make('name')->label(__('Title'))->required(),
                TextInput::make('url')->label('URL')->url()->required()
                    ->visible(fn (Get $get) => blank($get('type'))),
                Text::make(fn (Get $get) => __('Linked :type entry', ['type' => str_replace('_', ' ', (string) $get('type'))]))
                    ->visible(fn (Get $get) => filled($get('type'))),
                Hidden::make('type'),
                Hidden::make('id'),
            ])
            ->columns(['sm' => 2])
            ->defaultItems(0)
            ->addActionLabel(__('Add link'))
            ->collapsible()
            ->itemLabel(fn (array $state) => $state['name'] ?? null);
    }

    /** Supplies used by an entry, as ledger movements (negative quantities). */
    public static function suppliesUsed(Vehicle $vehicle): Repeater
    {
        $options = fn () => $vehicle->supplies()->withStock()->orderBy('description')->get()
            ->mapWithKeys(fn (Supply $s) => [$s->getKey() => $s->description.($s->part_number ? " ({$s->part_number})" : '').' — '.__(':stock in stock', ['stock' => (float) $s->stock])]);

        return Repeater::make('supplyUsages')
            ->label(__('Supplies used'))
            ->relationship()
            ->schema([
                Select::make('supply_id')->label(__('Supply'))->options($options)->required()->searchable()->live(),
                static::decimal(TextInput::make('quantity')->label(__('Quantity'))->required()
                    ->minValue(0.001)
                    ->maxValue(fn (Get $get, ?SupplyMovement $record) => ($supply = $vehicle->supplies()->find($get('supply_id')))
                        ? round($supply->stock() + abs((float) ($record?->quantity ?? 0)), 3)
                        : null)),
            ])
            ->columns(['sm' => 2])
            ->defaultItems(0)
            ->addActionLabel(__('Use a supply'))
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => ['quantity' => abs((float) $data['quantity'])] + $data)
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, Get $get) => static::usageMovement($data, $get('date')))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, Get $get, SupplyMovement $record) => static::usageMovement($data, $get('date'), $record));
    }

    private static function usageMovement(array $data, ?string $date, ?SupplyMovement $existing = null): array
    {
        $supply = Supply::query()->findOrFail($data['supply_id']);
        $quantity = abs((float) $data['quantity']);

        // Saving an entry again keeps the price its usage was booked at; only a different
        // supply or quantity is priced again (today's average would also count the usage itself).
        $unchanged = $existing !== null
            && (int) $existing->supply_id === (int) $supply->getKey()
            && abs(abs((float) $existing->quantity) - $quantity) < 0.0005;

        return [
            'supply_id' => $supply->getKey(),
            'type' => 'use',
            'quantity' => -$quantity,
            'cost' => $unchanged ? (float) $existing->cost : -(float) Supplies::costFor($supply, $quantity),
            'date' => $date ?? Clock::today()->toDateString(),
        ];
    }
}
