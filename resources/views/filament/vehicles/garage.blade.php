@php
    use App\Filament\Resources\Vehicles\VehicleResource;
    use App\Support\Format;

    $total = $this->totalVehicles();
    $sold = $this->soldVehicles();
    $vehicles = $this->vehicles();
    $user = auth()->user();
    $showReminders = $user->showsVehicleTab(\App\Enums\VehicleTab::Reminders);
    $attention = $showReminders ? $this->attention() : collect();
    $quickAdds = array_filter([
        $user->showsVehicleTab(\App\Enums\VehicleTab::Fuel) ? ['addFuel', 'tpms-fuel', __('Fuel')] : null,
        $user->showsVehicleTab(\App\Enums\VehicleTab::Log) ? ['addRecord', 'heroicon-o-wrench-screwdriver', __('Log')] : null,
        $user->showsVehicleTab(\App\Enums\VehicleTab::Odometer) ? ['addReading', 'heroicon-o-map', __('Odometer')] : null,
    ]);
    $showTools = $total > 4 || $sold > 0;
@endphp

<x-filament-panels::page>
    @if ($total === 0)
        {{-- Empty garage --}}
        <div class="mx-auto flex max-w-md flex-col items-center rounded-3xl border border-dashed border-gray-300 px-6 py-14 text-center dark:border-white/15">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                <x-filament::icon icon="tpms-garage" class="h-8 w-8" />
            </div>
            <h2 class="mt-5 text-lg font-semibold text-gray-950 dark:text-white">{{ __('Your garage is empty') }}</h2>
            @php
                $canImport = $this->importAction->isVisible();
            @endphp
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ $canImport
                    ? __('Add a vehicle to start logging fuel, services and reminders, or bring everything over from LubeLogger.')
                    : __('Add a vehicle to start logging fuel, services and reminders. Vehicles others share with you appear here too.') }}
            </p>
            {{-- Rendering a hidden action directly would show it as a disabled button. --}}
            <div class="mt-6 flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                {{-- The header's "Add vehicle" is only a "+" on phones: offer it here in words. --}}
                <x-filament::button icon="heroicon-o-plus" wire:click="mountAction('create')">{{ __('Add vehicle') }}</x-filament::button>
                @if ($canImport)
                    {{ $this->importAction }}
                @endif
            </div>
        </div>
    @else
        {{-- Needs attention --}}
        @if ($attention->isNotEmpty())
            <section class="overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <header class="flex items-center gap-2 px-4 pb-1 pt-4 sm:px-5">
                    <x-filament::icon icon="heroicon-s-bell-alert" class="h-5 w-5 text-warning-500" />
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Needs attention') }}</h2>
                    <span class="ms-auto rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $attention->count() }}</span>
                </header>
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($attention as $item)
                        @php
                            $reminder = $item['reminder'];
                            $thumb = $reminder->vehicle->getFirstMedia('photos');
                        @endphp
                        <li class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <a href="{{ VehicleResource::getUrl('reminders', ['record' => $reminder->vehicle_id]) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="relative shrink-0">
                                    @if ($thumb)
                                        <img src="{{ $thumb->getUrl('thumb') }}" alt="" class="size-10 rounded-xl object-cover">
                                    @else
                                        <span class="flex size-10 items-center justify-center rounded-xl bg-gray-100 text-gray-400 dark:bg-white/5">
                                            <x-filament::icon icon="tpms-car" class="size-5" />
                                        </span>
                                    @endif
                                    <span @class([
                                        'absolute -right-0.5 -top-0.5 size-3 rounded-full ring-2 ring-white dark:ring-gray-900',
                                        'bg-danger-500' => $item['urgency']->severity() >= 2,
                                        'bg-warning-500' => $item['urgency']->severity() === 1,
                                    ])></span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">{{ $reminder->description }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $reminder->vehicle->display_name }} ·
                                        <span @class(['font-medium', 'text-danger-600 dark:text-danger-400' => $item['urgency']->severity() >= 2])>{{ $item['due'] }}</span>
                                    </span>
                                </span>
                            </a>
                            <div class="shrink-0">
                                {{ ($this->completeReminderAction)(['reminder' => $reminder->getKey()]) }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Search and sold toggle, only when they help --}}
        @if ($showTools)
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                @if ($total > 4)
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="sm:max-w-xs sm:flex-1">
                        <x-filament::input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Search make, model or plate')" />
                    </x-filament::input.wrapper>
                @endif
                @if ($sold > 0)
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300 sm:ms-auto">
                        <x-filament::input.checkbox wire:model.live="showSold" />
                        {{ trans_choice('Show :count sold vehicle|Show :count sold vehicles', $sold, ['count' => $sold]) }}
                    </label>
                @endif
            </div>
        @endif

        {{-- Vehicle cards --}}
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 2xl:grid-cols-3">
            @forelse ($vehicles as ['vehicle' => $vehicle, 'card' => $card])
                @php
                    $cover = $vehicle->getFirstMedia('photos');
                    $unit = $vehicle->distanceUnit();
                    $reminder = $showReminders ? $card['reminder'] : null;
                    $severity = $reminder ? $reminder['urgency']->severity() : -1;
                    $stats = [
                        [__('Economy'), $card['economy'] ? \Illuminate\Support\Number::format($card['economy']['value'], precision: 1) : '—', $card['economy']['unit'] ?? null],
                        [__('This year'), Format::money($card['year_cost']), null],
                        [__('Per :unit', ['unit' => $unit->short()]), $card['cost_per_distance'] !== null ? Format::money($card['cost_per_distance']) : '—', null],
                    ];
                @endphp

                <article wire:key="vehicle-{{ $vehicle->getKey() }}"
                         class="flex flex-col overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-gray-950/5 transition duration-200 hover:shadow-lg dark:bg-gray-900 dark:ring-white/10">
                    <a href="{{ VehicleResource::getUrl('view', ['record' => $vehicle]) }}" wire:navigate
                       class="group flex flex-1 flex-col focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500">

                        {{-- Photo: name, plate and odometer on top --}}
                        <div class="relative aspect-[16/10] overflow-hidden bg-gray-900">
                            @if ($cover)
                                <img src="{{ $cover->getUrl('thumb') }}" alt="" loading="lazy"
                                     @class(['absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]', 'grayscale' => $vehicle->isSold()])>
                            @else
                                <div class="absolute inset-0 bg-[radial-gradient(circle_at_75%_15%,color-mix(in_oklab,var(--primary-500)_45%,transparent),transparent_65%)]"></div>
                                <x-filament::icon icon="tpms-car" class="absolute right-6 top-6 size-20 text-white/15" />
                            @endif

                            <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/25 to-black/0"></div>

                            <div class="absolute inset-x-3 top-3 flex items-start justify-between gap-2">
                                @if ($vehicle->isSold())
                                    <span class="rounded-full bg-black/60 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur">{{ __('Sold') }}</span>
                                @else
                                    <span></span>
                                @endif
                                @if ($severity >= 1)
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold text-white shadow-sm',
                                        'bg-danger-600' => $severity >= 2,
                                        'bg-warning-600' => $severity === 1,
                                    ])>
                                        <x-filament::icon :icon="$reminder['urgency']->getIcon()" class="h-3.5 w-3.5" />
                                        {{ $reminder['urgency']->getLabel() }}
                                    </span>
                                @endif
                            </div>

                            <div class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 p-4">
                                <div class="min-w-0">
                                    @if ($vehicle->nickname)
                                        <p class="truncate text-xs font-medium text-white/75">{{ $vehicle->full_name }}</p>
                                    @endif
                                    <h2 class="truncate text-xl font-bold leading-tight tracking-tight text-white">{{ $vehicle->display_name }}</h2>
                                    @if ($vehicle->license_plate)
                                        <span class="mt-2 inline-block rounded-md bg-white px-1.5 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-gray-900 shadow-sm ring-1 ring-black/20">{{ $vehicle->license_plate }}</span>
                                    @endif
                                </div>
                                <div class="shrink-0 rounded-2xl bg-white/15 px-3 py-1.5 text-right ring-1 ring-white/20 backdrop-blur-md">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-white/70">{{ __('Odometer') }}</p>
                                    <p class="text-sm font-semibold tabular-nums text-white">{{ Format::distance($card['odometer'], $unit) }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- Numbers --}}
                        <dl class="grid grid-cols-3 gap-px bg-gray-100 dark:bg-white/5">
                            @foreach ($stats as [$label, $value, $suffix])
                                <div class="min-w-0 bg-white px-4 py-3 dark:bg-gray-900">
                                    <dt class="truncate text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                    <dd class="mt-0.5 truncate text-[15px] font-semibold tabular-nums text-gray-950 dark:text-white">
                                        {{ $value }}@if ($suffix)<span class="ms-1 text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $suffix }}</span>@endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        {{-- Next reminder --}}
                        @if ($showReminders)
                        <div class="flex items-center gap-2.5 border-t border-gray-100 px-4 py-3 text-sm dark:border-white/5">
                            @if ($reminder)
                                <span @class([
                                    'size-2 shrink-0 rounded-full',
                                    'bg-danger-500' => $severity >= 2,
                                    'bg-warning-500' => $severity === 1,
                                    'bg-success-500' => $severity < 1,
                                ])></span>
                                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200">{{ $reminder['reminder']->description }}</span>
                                <span @class([
                                    'shrink-0 text-xs tabular-nums',
                                    'font-semibold text-danger-600 dark:text-danger-400' => $severity >= 2,
                                    'font-medium text-warning-700 dark:text-warning-400' => $severity === 1,
                                    'text-gray-500 dark:text-gray-400' => $severity < 1,
                                ])>{{ \App\Support\ReminderText::due($reminder['reminder'], $card['odometer']) }}</span>
                            @else
                                <x-filament::icon icon="heroicon-o-check-circle" class="size-4 shrink-0 text-gray-400" />
                                <span class="text-gray-500 dark:text-gray-400">{{ __('No reminders set') }}</span>
                            @endif
                        </div>
                        @endif
                    </a>

                    {{-- Quick add, without opening the vehicle --}}
                    @if (! $vehicle->isSold() && $quickAdds)
                        <div @class(['grid divide-x divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5', 'grid-cols-1' => count($quickAdds) === 1, 'grid-cols-2' => count($quickAdds) === 2, 'grid-cols-3' => count($quickAdds) === 3])>
                            @foreach ($quickAdds as [$action, $icon, $label])
                                <button type="button"
                                        wire:click="mountAction('{{ $action }}', { vehicle: {{ $vehicle->getKey() }} })"
                                        wire:loading.attr="disabled"
                                        class="flex h-12 items-center justify-center gap-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 active:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5 dark:active:bg-white/10">
                                    <x-filament::icon :icon="$icon" class="size-5 text-primary-600 dark:text-primary-400" />
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </article>
            @empty
                <p class="col-span-full py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No vehicles match your search.') }}</p>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
