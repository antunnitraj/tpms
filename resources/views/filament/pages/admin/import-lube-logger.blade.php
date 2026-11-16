@php
    $import = $this->getImport();
    $preview = $import?->preview;
@endphp

<x-filament-panels::page>
    @if (! $import)
        <x-filament::section>
            <x-slot name="heading">{{ __('Upload your LubeLogger backup') }}</x-slot>
            <x-slot name="description">
                {{ __('Nothing is imported yet: you will see every vehicle, user and record count first and can adjust how the backup is read.') }}
            </x-slot>

            <form wire:submit="analyze" class="grid grid-cols-1 gap-6">
                {{ $this->uploadForm }}

                <div>
                    <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" class="w-full sm:w-auto">
                        <span wire:loading.remove wire:target="analyze">{{ __('Read backup') }}</span>
                        <span wire:loading wire:target="analyze">{{ __('Reading backup…') }}</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

    @elseif ($import->status === \App\Models\BackupImport::STATUS_PREVIEWED && $preview)
        @php
            $vehicles = $preview['vehicles'];
            $excluded = $preview['excluded_vehicles'] ?? [];
            $logCount = fn (array $v) => $v['counts']['service'] + $v['counts']['repair'] + $v['counts']['upgrade'] + $v['counts']['tax_fee'];
            $totals = [
                [__('Vehicles'), count($vehicles)],
                [__('Log entries'), collect($vehicles)->sum($logCount)],
                [__('Fuel-ups'), collect($vehicles)->sum('counts.fuel')],
                [__('Files'), collect($vehicles)->sum('counts.files')],
            ];
        @endphp

        <div class="grid grid-cols-1 gap-6">
            {{-- Where you are, what's in the backup, and the buttons that matter --}}
            <section class="overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <ol class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 text-xs font-medium sm:px-6 dark:border-white/5">
                    @foreach ([__('Upload'), __('Review'), __('Import')] as $i => $step)
                        <li @class(['flex items-center gap-1.5', 'text-primary-700 dark:text-primary-400' => $i === 1, 'text-gray-400 dark:text-gray-500' => $i !== 1])>
                            <span @class([
                                'flex size-5 items-center justify-center rounded-full text-[11px]',
                                'bg-(--tpms-solid-bg) text-(--tpms-solid-fg)' => $i <= 1,
                                'ring-1 ring-gray-300 dark:ring-white/20' => $i > 1,
                            ])>
                                @if ($i === 0)<x-filament::icon icon="heroicon-m-check" class="size-3" />@else{{ $i + 1 }}@endif
                            </span>
                            {{ $step }}
                        </li>
                        @if (! $loop->last)<li class="h-px w-6 bg-gray-200 dark:bg-white/10" aria-hidden="true"></li>@endif
                    @endforeach
                </ol>

                @if ($previous = $this->previousImport())
                    <div class="mx-4 mt-4 flex gap-2 rounded-xl bg-warning-50 p-3 text-sm text-warning-700 sm:mx-6 dark:bg-warning-400/10 dark:text-warning-400">
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="size-5 shrink-0" />
                        {{ __('This exact backup was already imported on :date.', ['date' => $previous->imported_at?->toFormattedDateString()]) }}
                    </div>
                @endif

                <div class="flex flex-col gap-5 p-4 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 truncate text-base font-semibold text-gray-950 dark:text-white">
                            <x-filament::icon icon="heroicon-o-archive-box" class="size-5 shrink-0 text-gray-400" />
                            <span class="truncate">{{ $import->filename }}</span>
                        </p>
                        <dl class="mt-3 grid grid-cols-4 gap-3 sm:flex sm:gap-6">
                            @foreach ($totals as [$label, $value])
                                <div class="min-w-0">
                                    <dt class="truncate text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                    <dd class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($value) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row lg:shrink-0">
                        {{ $this->cancelAction }}
                        {{ $this->importAction }}
                    </div>
                </div>

            </section>

            {{-- Vehicles: one card each, with its own import switch and units --}}
            <section class="grid grid-cols-1 gap-4">
                <div class="flex items-end justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Vehicles') }}</h2>
                    <p class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <x-filament::loading-indicator wire:loading.delay wire:target="options,refreshPreview" class="size-4" />
                        {{ __(':count of :total will be imported', ['count' => count($vehicles), 'total' => count($vehicles) + count($excluded)]) }}
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($vehicles as $vehicle)
                        @php
                            $id = $vehicle['source_id'];
                            $c = $vehicle['counts'];
                            $chips = array_filter([
                                ...collect(\App\Enums\RecordCategory::cases())->mapWithKeys(fn ($category) => [$category->value => [$c[$category->value], $c[$category->value].' '.$category->getLabel(), $category->getColor(), $category->getIcon()]])->all(),
                                'reminders' => [$c['reminders'], trans_choice(':count reminder|:count reminders', $c['reminders']), 'gray', 'heroicon-m-bell'],
                                'plans' => [$c['plans'], trans_choice(':count plan|:count plans', $c['plans']), 'gray', 'heroicon-m-clipboard-document-list'],
                                'supplies' => [$c['supplies'], trans_choice(':count supply|:count supplies', $c['supplies']), 'gray', 'heroicon-m-archive-box'],
                                'notes' => [$c['notes'], trans_choice(':count note|:count notes', $c['notes']), 'gray', 'heroicon-m-document-text'],
                                'equipment' => [$c['equipment'], trans_choice(':count equipment item|:count equipment items', $c['equipment']), 'gray', 'tpms-oil'],
                                'odometer' => [$c['odometer'], trans_choice(':count reading|:count readings', $c['odometer']), 'gray', 'heroicon-m-map'],
                                'files' => [$c['files'], trans_choice(':count file|:count files', $c['files']), 'gray', 'heroicon-m-photo'],
                            ], fn ($chip) => $chip[0] > 0);
                        @endphp

                        <article wire:key="import-vehicle-{{ $id }}" class="flex flex-col overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            {{-- Photo with name, plate and the import switch --}}
                            <div class="relative aspect-[16/9] overflow-hidden bg-gray-900">
                                @if ($vehicle['photo'])
                                    <img src="{{ route('import-preview.file', ['import' => $import, 'path' => $vehicle['photo']]) }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                                @else
                                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_75%_15%,color-mix(in_oklab,var(--primary-500)_45%,transparent),transparent_65%)]"></div>
                                    <x-filament::icon icon="tpms-car" class="absolute right-6 top-6 size-16 text-white/15" />
                                @endif
                                <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/20 to-black/0"></div>

                                <label class="absolute right-3 top-3 inline-flex cursor-pointer items-center gap-2 rounded-full bg-black/55 py-1 pe-1 ps-3 text-xs font-semibold text-white ring-1 ring-white/15 backdrop-blur">
                                    {{ __('Import') }}
                                    <input type="checkbox" class="peer sr-only" wire:model.live="options.vehicles.{{ $id }}.import">
                                    <span class="relative h-6 w-10 rounded-full bg-white/25 transition after:absolute after:start-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-primary-500 peer-checked:after:translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-white"></span>
                                </label>

                                <div class="absolute inset-x-0 bottom-0 p-4">
                                    <h3 class="truncate text-xl font-bold leading-tight tracking-tight text-white">{{ $vehicle['title'] }}</h3>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-white/80">
                                        @if ($vehicle['license_plate'])
                                            <span class="rounded-md bg-white px-1.5 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-gray-900 ring-1 ring-black/20">{{ $vehicle['license_plate'] }}</span>
                                        @endif
                                        @if ($vehicle['purchase_date'])
                                            <span>{{ __('Bought :date', ['date' => \Carbon\Carbon::parse($vehicle['purchase_date'])->toFormattedDateString()]) }}</span>
                                        @endif
                                        @if ($vehicle['sold_date'])
                                            <span>· {{ __('Sold :date', ['date' => \Carbon\Carbon::parse($vehicle['sold_date'])->toFormattedDateString()]) }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Headline numbers --}}
                            <dl class="grid grid-cols-3 gap-px bg-gray-100 dark:bg-white/5">
                                @foreach ([[__('Log entries'), number_format($logCount($vehicle))], [__('Fuel-ups'), number_format($c['fuel'])], [__('Spent'), \App\Support\Format::money($vehicle['total_cost'])]] as [$label, $value])
                                    <div class="min-w-0 bg-white px-4 py-3 dark:bg-gray-900">
                                        <dt class="truncate text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                        <dd class="mt-0.5 truncate text-[15px] font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            <div class="grid grid-cols-1 gap-4 p-4">
                                {{-- Everything else that comes along --}}
                                @if ($chips)
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($chips as [$count, $label, $color, $icon])
                                            <x-filament::badge :color="$color" :icon="$icon">{{ $label }}</x-filament::badge>
                                        @endforeach
                                    </div>
                                @endif

                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    @if ($vehicle['first_date'])
                                        {{ \Carbon\Carbon::parse($vehicle['first_date'])->toFormattedDateString() }} – {{ \Carbon\Carbon::parse($vehicle['last_date'])->toFormattedDateString() }} ·
                                    @endif
                                    {{ __('Access: :users', ['users' => implode(', ', $vehicle['users'])]) }}
                                    @if ($c['odometer_skipped'])
                                        <br>{{ __(':count automatic odometer copies skipped', ['count' => $c['odometer_skipped']]) }}
                                    @endif
                                </p>

                                {{-- Units, in case LubeLogger's settings were read wrong --}}
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="grid gap-1">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Distance in') }}</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="options.vehicles.{{ $id }}.distance_unit">
                                                @foreach (\App\Enums\DistanceUnit::cases() as $unit)
                                                    <option value="{{ $unit->value }}">{{ $unit->getLabel() }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </label>
                                    <label class="grid gap-1">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Fuel in') }}</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="options.vehicles.{{ $id }}.fuel_unit">
                                                @foreach (\App\Enums\FuelUnit::cases() as $unit)
                                                    <option value="{{ $unit->value }}">{{ $unit->getLabel() }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </label>
                                </div>
                            </div>

                            @if ($vehicle['samples']['records'] || $vehicle['samples']['fuel'])
                                <details class="group mt-auto border-t border-gray-100 dark:border-white/5">
                                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('Latest entries') }}
                                        <x-filament::icon icon="heroicon-m-chevron-down" class="size-4 text-gray-400 transition group-open:rotate-180" />
                                    </summary>
                                    <ul class="divide-y divide-gray-100 px-4 pb-3 text-sm dark:divide-white/5">
                                        @foreach ($vehicle['samples']['records'] as $record)
                                            @php
                                                $category = \App\Enums\RecordCategory::from($record['category']);
                                            @endphp
                                            <li class="flex items-center gap-3 py-2">
                                                <x-filament::icon :icon="$category->getIcon()" class="size-4 shrink-0 text-gray-400" />
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-gray-950 dark:text-white">{{ $record['description'] }}</span>
                                                    <span class="block text-xs text-gray-500">{{ \Carbon\Carbon::parse($record['date'])->toFormattedDateString() }} · {{ $category->getLabel() }}</span>
                                                </span>
                                                <span class="shrink-0 tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Format::money($record['cost']) }}</span>
                                            </li>
                                        @endforeach
                                        @foreach ($vehicle['samples']['fuel'] as $fuel)
                                            <li class="flex items-center gap-3 py-2">
                                                <x-filament::icon icon="tpms-fuel" class="size-4 shrink-0 text-gray-400" />
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-gray-950 dark:text-white">{{ __('Fuel-up') }} · {{ \Illuminate\Support\Number::format($fuel['quantity'], maxPrecision: 2) }}</span>
                                                    <span class="block text-xs text-gray-500">{{ \Carbon\Carbon::parse($fuel['date'])->toFormattedDateString() }}@if ($fuel['mileage']) · {{ number_format($fuel['mileage']) }}@endif</span>
                                                </span>
                                                <span class="shrink-0 tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Format::money($fuel['cost']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </article>
                    @endforeach

                    {{-- Switched off: kept here so they can be switched back on --}}
                    @foreach ($excluded as $vehicle)
                        <article wire:key="import-vehicle-{{ $vehicle['source_id'] }}" class="flex items-center gap-3 self-start rounded-3xl border border-dashed border-gray-300 p-3 dark:border-white/15">
                            @if ($vehicle['photo'])
                                <img src="{{ route('import-preview.file', ['import' => $import, 'path' => $vehicle['photo']]) }}" alt="" class="size-14 shrink-0 rounded-2xl object-cover opacity-60 grayscale">
                            @else
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-gray-100 text-gray-400 dark:bg-white/5">
                                    <x-filament::icon icon="tpms-car" class="size-6" />
                                </span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-gray-500 dark:text-gray-400">{{ $vehicle['title'] }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('Not imported') }}@if ($vehicle['license_plate']) · {{ $vehicle['license_plate'] }}@endif</p>
                            </div>
                            <label class="inline-flex shrink-0 cursor-pointer items-center gap-2 text-xs font-semibold text-gray-600 dark:text-gray-300">
                                {{ __('Import') }}
                                <input type="checkbox" class="peer sr-only" wire:model.live="options.vehicles.{{ $vehicle['source_id'] }}.import">
                                <span class="relative h-6 w-10 rounded-full bg-gray-200 transition after:absolute after:start-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-primary-500 peer-checked:after:translate-x-4 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500 dark:bg-white/15"></span>
                            </label>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Options --}}
            <form wire:submit.prevent>
                {{ $this->optionsForm }}
            </form>

            {{-- Summary --}}
            <x-filament::section :heading="__('What will be imported')" :description="__('Counts from the backup and what lands in The Pocket Mechanic.')">
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach ($preview['summary'] as $row)
                        @continue(! $row['source'] && ! $row['imported'])
                        <li class="flex items-start justify-between gap-4 py-2.5">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</p>
                                @if (! empty($row['note']))<p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</p>@endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2 tabular-nums">
                                @if ($row['source'] !== null && $row['source'] !== $row['imported'])
                                    <span class="text-gray-400 line-through decoration-gray-300 dark:decoration-white/20">{{ number_format($row['source']) }}</span>
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="size-3.5 text-gray-400" />
                                @endif
                                <span class="min-w-8 text-right font-semibold text-gray-950 dark:text-white">{{ number_format($row['imported']) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>

            @if ($preview['warnings'])
                <x-filament::section collapsible collapsed icon="heroicon-o-exclamation-triangle" icon-color="warning"
                                     :heading="trans_choice(':count thing to know|:count things to know', count($preview['warnings']), ['count' => count($preview['warnings'])])">
                    <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-300">
                        @foreach (array_slice($preview['warnings'], 0, 50) as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                    @if (count($preview['warnings']) > 50)
                        <p class="mt-2 text-sm text-gray-500">{{ __('…and :count more.', ['count' => count($preview['warnings']) - 50]) }}</p>
                    @endif
                </x-filament::section>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                {{ $this->cancelAction }}
                {{ $this->importAction }}
            </div>
        </div>
    @else
        @php
            $undone = $import->status === \App\Models\BackupImport::STATUS_UNDONE;
            $labels = [
                'vehicles' => [__('Vehicles'), 'tpms-car'], 'records' => [__('Log entries'), 'heroicon-o-wrench-screwdriver'],
                'fuel_records' => [__('Fuel-ups'), 'tpms-fuel'], 'odometer_readings' => [__('Odometer readings'), 'heroicon-o-map'],
                'reminders' => [__('Reminders'), 'heroicon-o-bell'], 'plans' => [__('Plans'), 'heroicon-o-clipboard-document-list'],
                'supplies' => [__('Supplies'), 'heroicon-o-archive-box'], 'notes' => [__('Notes'), 'heroicon-o-document-text'],
                'equipment' => [__('Equipment'), 'tpms-oil'], 'files' => [__('Photos & files'), 'heroicon-o-photo'],
                'users' => [__('New accounts'), 'heroicon-o-user-plus'],
            ];
        @endphp
        <section class="overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-col items-center gap-3 px-6 pb-6 pt-8 text-center">
                <span @class([
                    'flex size-14 items-center justify-center rounded-full',
                    'bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400' => ! $undone,
                    'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300' => $undone,
                ])>
                    <x-filament::icon :icon="$undone ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check'" class="size-7" />
                </span>
                <h2 class="text-xl font-semibold text-gray-950 dark:text-white">{{ $undone ? __('Import undone') : __('Import complete') }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $undone ? __('Everything this import added has been removed.') : __(':file is in The Pocket Mechanic now.', ['file' => $import->filename]) }}
                </p>
            </div>

            @unless ($undone)
                <dl class="grid grid-cols-2 gap-px border-t border-gray-100 bg-gray-100 sm:grid-cols-3 dark:border-white/5 dark:bg-white/5">
                    @php($counts = $import->summary['counts'] ?? [])
                    @foreach (array_replace(array_intersect_key($labels, $counts), $counts) as $what => $count)
                        <div class="flex items-center gap-3 bg-white px-4 py-3 dark:bg-gray-900">
                            <x-filament::icon :icon="$labels[$what][1] ?? 'heroicon-o-check'" class="size-5 shrink-0 text-gray-400" />
                            <div class="min-w-0">
                                <dt class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $labels[$what][0] ?? \Illuminate\Support\Str::headline($what) }}</dt>
                                <dd class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($count) }}</dd>
                            </div>
                        </div>
                    @endforeach
                </dl>
            @endunless

            @if ($import->warnings && ! $undone)
                <details class="border-t border-gray-100 px-4 py-3 text-sm dark:border-white/5">
                    <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">{{ trans_choice(':count thing to know|:count things to know', count($import->warnings), ['count' => count($import->warnings)]) }}</summary>
                    <ul class="mt-2 list-disc space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                        @foreach ($import->warnings as $warning)<li>{{ $warning }}</li>@endforeach
                    </ul>
                </details>
            @endif

            <div class="flex flex-col-reverse gap-2 border-t border-gray-100 p-4 sm:flex-row sm:justify-end dark:border-white/5">
                @if ($import->status === \App\Models\BackupImport::STATUS_IMPORTED)
                    {{ ($this->undoAction)(['import' => $import->getKey()]) }}
                @endif
                {{ $this->cancelAction->label(__('Import another backup')) }}
                <x-filament::button tag="a" :href="\Filament\Facades\Filament::getUrl()" icon="tpms-garage">{{ __('Go to the garage') }}</x-filament::button>
            </div>
        </section>
    @endif
</x-filament-panels::page>
