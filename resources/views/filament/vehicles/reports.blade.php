@php
    $breakdown = $this->breakdown();
    $unit = $this->getRecord()->distanceUnit()->short();
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6">
        <div class="flex items-center gap-3">
            <label for="report-year" class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Period') }}</label>
            <x-filament::input.wrapper class="w-40">
                <x-filament::input.select id="report-year" wire:model.live="year">
                    <option value="all">{{ __('All time') }}</option>
                    @foreach ($this->years() as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        {{-- What counts towards the costs: checkbox chips, dot colours match the charts. --}}
        <fieldset class="flex flex-wrap gap-2">
            <legend class="sr-only">{{ __('Include in costs') }}</legend>
            @foreach (\App\Services\VehicleStats::costCategories() as $key => $label)
                <label
                    wire:key="report-category-{{ $key }}"
                    class="group inline-flex h-10 cursor-pointer select-none items-center gap-2 rounded-full bg-white ps-2.5 pe-3.5 text-sm font-medium text-gray-500 shadow-xs ring-1 ring-gray-950/10 transition has-checked:text-gray-950 has-focus-visible:ring-2 has-focus-visible:ring-primary-600 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10 dark:has-checked:text-white"
                >
                    <input type="checkbox" value="{{ $key }}" wire:model.live="categories" class="sr-only">
                    <span
                        class="flex size-5 items-center justify-center rounded-full border-2 border-gray-300 text-white transition group-has-checked:border-transparent group-has-checked:bg-(--dot) dark:border-gray-600"
                        style="--dot: {{ \App\Filament\Resources\Vehicles\Widgets\VehicleChart::COLORS[$key] }}"
                    >
                        <x-filament::icon icon="heroicon-m-check" class="hidden size-3.5 group-has-checked:block" />
                    </span>
                    {{ $label }}
                </label>
            @endforeach
        </fieldset>
    </div>

    {{-- Rendered here rather than as a header widget so the filters above come first. --}}
    @livewire(\App\Filament\Resources\Vehicles\Widgets\VehicleStatsOverview::class, [
        'record' => $this->getRecord(),
        'year' => $this->selectedYear(),
        'categories' => $this->categories,
    ], key('report-stats-'.$this->getRecord()->getKey()))

    <x-filament::section :heading="__('Cost breakdown')" compact>
        <x-slot name="description">
            {{ collect([
                $breakdown['days'] ? trans_choice(':count day|:count days', $breakdown['days'], ['count' => number_format($breakdown['days'])]) : null,
                $breakdown['distance'] ? number_format($breakdown['distance']).' '.$unit : null,
            ])->filter()->implode(' · ') }}
        </x-slot>

        @if ($this->categories === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Pick at least one category to see costs.') }}</p>
        @else
            <div class="-mx-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-2 py-1 font-medium">{{ __('Category') }}</th>
                            <th class="px-2 py-1 text-right font-medium">{{ __('Total') }}</th>
                            <th class="px-2 py-1 text-right font-medium">{{ __('Per day') }}</th>
                            <th class="px-2 py-1 text-right font-medium">{{ __('Per :unit', ['unit' => $unit]) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ([...$breakdown['rows'], $breakdown['total']] as $row)
                            <tr @class(['font-semibold' => $loop->last])>
                                <td class="px-2 py-1.5">{{ $row['label'] }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ \App\Support\Format::money($row['total']) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['per_day'] !== null ? \App\Support\Format::money($row['per_day']) : '—' }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ $row['per_distance'] !== null ? \App\Support\Format::money($row['per_distance']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($breakdown['depreciation'] !== null)
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                {{ $breakdown['depreciation'] >= 0 ? __('Depreciation') : __('Appreciation') }}:
                <span class="font-medium">{{ \App\Support\Format::money(abs($breakdown['depreciation'])) }}</span>
            </p>
        @endif
    </x-filament::section>

    @teleport('body')
        @include('filament.vehicles.print', [
            'vehicle' => $this->getRecord(),
            'year' => $this->selectedYear(),
            'categories' => $this->categories,
            'breakdown' => $breakdown,
            'printout' => $this->printout(),
        ])
    @endteleport
</x-filament-panels::page>
