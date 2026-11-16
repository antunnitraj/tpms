@php
    $vehicle = $this->vehicle();
    $cover = $vehicle->getFirstMedia('photos');
    $reminders = $this->dueReminders();
    $notes = $vehicle->notes()->where('pinned', true)->latest()->limit(3)->get();
    $activity = $this->recentActivity();
    $unit = $vehicle->distanceUnit();
    $user = auth()->user();
    $quickAdds = array_filter([
        $user->showsVehicleTab(\App\Enums\VehicleTab::Fuel) ? ['addFuel', 'tpms-fuel', $vehicle->fuelUnit() === \App\Enums\FuelUnit::KilowattHours ? __('Charge') : __('Fuel')] : null,
        $user->showsVehicleTab(\App\Enums\VehicleTab::Log) ? ['addRecord', 'heroicon-o-wrench-screwdriver', __('Log entry')] : null,
        $user->showsVehicleTab(\App\Enums\VehicleTab::Odometer) ? ['addReading', 'heroicon-o-map', __('Odometer')] : null,
    ]);
@endphp

<x-filament-panels::page class="tpms-overview">
    {{-- Quick add: the three things people log most (if their tabs are shown), thumb-sized on phones. --}}
    @if ($quickAdds)
    <div @class(['grid gap-3', 'grid-cols-1' => count($quickAdds) === 1, 'grid-cols-2' => count($quickAdds) === 2, 'grid-cols-3' => count($quickAdds) === 3])>
        @foreach ($quickAdds as [$action, $icon, $label])
            <button type="button" wire:click="mountAction('{{ $action }}')" wire:loading.attr="disabled"
                    class="group flex flex-col items-center gap-2 rounded-2xl bg-white px-2 py-3.5 text-sm font-medium text-gray-700 shadow-xs ring-1 ring-gray-950/5 transition hover:bg-primary-50 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 active:scale-[0.97] dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-primary-400/10 dark:hover:text-primary-300">
                <span class="relative flex h-11 w-11 items-center justify-center rounded-full bg-primary-50 text-primary-700 transition group-hover:bg-primary-100 dark:bg-primary-400/10 dark:text-primary-400 dark:group-hover:bg-primary-400/20">
                    <x-filament::icon :icon="$icon" class="h-5 w-5" />
                    <span class="absolute -bottom-0.5 -right-0.5 flex h-[18px] w-[18px] items-center justify-center rounded-full bg-(--tpms-solid-bg) text-(--tpms-solid-fg) ring-2 ring-white dark:ring-gray-900">
                        <x-filament::icon icon="heroicon-m-plus" class="h-3 w-3" />
                    </span>
                </span>
                <span class="truncate">{{ $label }}</span>
            </button>
        @endforeach
    </div>
    @endif

    @livewire(\App\Filament\Resources\Vehicles\Widgets\VehicleStatsOverview::class, ['record' => $vehicle], key('stats-'.$vehicle->getKey()))

    <div class="grid grid-cols-1 gap-3 sm:gap-6 2xl:grid-cols-3">
        <div class="grid grid-cols-1 content-start gap-3 sm:gap-6 2xl:col-span-2">
            @if ($cover)
                <a href="{{ \App\Filament\Resources\Vehicles\VehicleResource::getUrl('media', ['record' => $vehicle]) }}"
                   class="block overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <img src="{{ $cover->getUrl('preview') }}" alt="" @class(['aspect-[16/9] w-full object-cover sm:aspect-[21/9]', 'grayscale' => $vehicle->isSold()])>
                </a>
            @endif

            @if ($user->showsVehicleTab(\App\Enums\VehicleTab::Reminders))
            <x-filament::section :heading="__('Reminders')" compact>
                <x-slot name="afterHeader">
                    <x-filament::link :href="\App\Filament\Resources\Vehicles\VehicleResource::getUrl('reminders', ['record' => $vehicle])" size="sm">{{ __('All') }}</x-filament::link>
                </x-slot>

                @forelse ($reminders as $item)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 py-2.5 last:border-0 dark:border-white/5">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 font-medium text-gray-950 dark:text-white">
                                {{-- On phones the badge doesn't fit: a dot and red text show what needs doing (as in the garage) --}}
                                @if ($item['urgency']->severity() >= 1)
                                    <span @class(['size-2.5 shrink-0 rounded-full sm:hidden', 'bg-danger-500' => $item['urgency']->severity() >= 2, 'bg-warning-500' => $item['urgency']->severity() === 1])></span>
                                @endif
                                <span class="truncate">{{ $item['reminder']->description }}</span>
                            </p>
                            <p @class(['text-sm', 'font-medium text-danger-600 dark:text-danger-400' => $item['urgency']->severity() >= 2, 'text-gray-500 dark:text-gray-400' => $item['urgency']->severity() < 2])>{{ \App\Support\ReminderText::due($item['reminder'], $item['mileage']) }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-filament::badge :color="$item['urgency']->getColor()" class="hidden sm:inline-flex">{{ $item['urgency']->getLabel() }}</x-filament::badge>
                            {{ ($this->completeReminderAction)(['reminder' => $item['reminder']->getKey()]) }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No reminders. Add one so you never miss an oil change.') }}</p>
                @endforelse
            </x-filament::section>
            @endif

            <x-filament::section :heading="__('Recent activity')" compact>
                @if ($user->showsVehicleTab(\App\Enums\VehicleTab::Log))
                    <x-slot name="afterHeader">
                        <x-filament::link :href="\App\Filament\Resources\Vehicles\VehicleResource::getUrl('log', ['record' => $vehicle])" size="sm">{{ __('Log') }}</x-filament::link>
                    </x-slot>
                @endif

                @forelse ($activity as $entry)
                    @php
                        $category = $entry->category ? \App\Enums\RecordCategory::from($entry->category) : null;
                    @endphp
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 py-2.5 last:border-0 dark:border-white/5">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-filament::icon :icon="$category?->getIcon() ?? 'tpms-fuel'" class="h-5 w-5 shrink-0 text-gray-400" />
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-950 dark:text-white">{{ $entry->description ?? __('Fuel-up') }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ \Carbon\Carbon::parse($entry->date)->toFormattedDateString() }}
                                    @if ($entry->mileage) · {{ \App\Support\Format::distance($entry->mileage, $unit) }} @endif
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Format::money($entry->cost) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing logged yet. Use the buttons above to add a fuel-up or a service.') }}</p>
                @endforelse
            </x-filament::section>
        </div>

        <div class="grid grid-cols-1 content-start gap-3 sm:gap-6">
            @foreach ($user->showsVehicleTab(\App\Enums\VehicleTab::Notes) ? $notes : [] as $note)
                <x-filament::section compact :heading="$note->title" icon="heroicon-o-map-pin">
                    <div class="fi-prose text-sm">{!! str($note->body ?? '')->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                </x-filament::section>
            @endforeach

            <x-filament::section :heading="__('Details')" compact>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    @foreach (array_filter([
                        __('Make & model') => $vehicle->full_name,
                        __('License plate') => $vehicle->license_plate,
                        __('Fuel') => $vehicle->fuel_type?->getLabel(),
                        __('Units') => $unit->short().' · '.$vehicle->fuelUnit()->short(),
                        __('Purchased') => $vehicle->purchase_date?->toFormattedDateString(),
                        __('Purchase price') => $vehicle->purchase_price !== null ? \App\Support\Format::money($vehicle->purchase_price) : null,
                        __('Sold') => $vehicle->sold_date?->toFormattedDateString(),
                        __('Sold for') => $vehicle->sold_price !== null ? \App\Support\Format::money($vehicle->sold_price) : null,
                    ]) as $label => $value)
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="truncate font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($vehicle->tags)
                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach ($vehicle->tags as $tag)<x-filament::badge color="gray" size="sm">{{ $tag }}</x-filament::badge>@endforeach
                    </div>
                @endif
                @if ($vehicle->notes)
                    <p class="mt-3 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $vehicle->notes }}</p>
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('Shared with')" compact>
                <ul class="text-sm">
                    @foreach ($vehicle->users as $user)
                        <li class="py-0.5">{{ $user->name }} <span class="text-gray-500">({{ $user->username }})</span></li>
                    @endforeach
                </ul>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
