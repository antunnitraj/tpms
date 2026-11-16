{{-- Vehicle settings: the current photos, managed on the Photos & files tab. --}}
@php
    $vehicle = $getRecord();
    $photos = $vehicle?->getMedia('photos') ?? collect();
@endphp

<div class="grid gap-3">
    @if ($photos->isNotEmpty())
        <div class="flex gap-2 overflow-x-auto pb-1">
            @foreach ($photos->take(6) as $photo)
                <span class="relative shrink-0">
                    <img src="{{ $photo->getUrl('thumb') }}" alt="" class="size-16 rounded-xl object-cover ring-1 ring-gray-950/5 dark:ring-white/10">
                    @if ($loop->first)
                        <x-filament::icon icon="heroicon-s-star" class="absolute start-1 top-1 size-3.5 text-white drop-shadow" />
                    @endif
                </span>
            @endforeach
            @if ($photos->count() > 6)
                <span class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-sm font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">+{{ $photos->count() - 6 }}</span>
            @endif
        </div>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No photos yet.') }}</p>
    @endif

    @if ($vehicle)
        <div>
            <x-filament::button tag="a" :href="\App\Filament\Resources\Vehicles\VehicleResource::getUrl('media', ['record' => $vehicle])" color="gray" size="sm" icon="heroicon-o-photo">
                {{ $photos->isNotEmpty() ? __('Manage photos') : __('Add photos') }}
            </x-filament::button>
        </div>
    @endif
</div>
