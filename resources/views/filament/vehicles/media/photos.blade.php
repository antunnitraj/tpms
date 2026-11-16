@php
    $photos = $this->photos();
    $items = $photos->map(fn ($m) => [
        'id' => $m->getKey(),
        'src' => $m->getUrl('preview'),
        'thumb' => $m->getUrl('thumb'),
        'download' => $m->getUrl().(str_contains($m->getUrl(), '?') ? '&' : '?').'download=1',
        'name' => $m->name,
    ])->values();
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Photos')" :description="__('Tap a photo to view it. Drag to reorder (on a phone: press and hold, then drag); the first photo is the cover.')">
        @if ($photos->isEmpty())
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <span class="flex size-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-camera" class="size-7" />
                </span>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No photos yet. The first one becomes the cover on the garage card.') }}</p>
                {{ $this->addPhotosAction }}
            </div>
        @else
            {{-- Re-created whenever the photos change (new key), so the grid and viewer always match the database. --}}
            <div
                wire:key="gallery-{{ md5($items->toJson()) }}"
                x-data="{
                    items: @js($items),
                    open: null,
                    startX: null,
                    selecting: false,
                    selected: [],
                    dragging: false,
                    sortable: null,
                    init() {
                        // Long-press to drag on touch screens, so a normal swipe still scrolls the page.
                        // forceFallback: in the browser's own drag mode the hold is cancelled by the
                        // slightest finger movement (under a pixel on an iPhone); this allows a few pixels.
                        this.sortable = window.Sortable.create(this.$refs.grid, {
                            animation: 200,
                            delay: 300,
                            delayOnTouchOnly: true,
                            touchStartThreshold: 8,
                            forceFallback: true,
                            fallbackTolerance: 4,
                            draggable: '[data-id]',
                            dataIdAttr: 'data-id',
                            ghostClass: 'opacity-40',
                            chosenClass: 'tpms-photo-chosen',
                            onStart: () => this.dragging = true,
                            onEnd: (event) => {
                                setTimeout(() => this.dragging = false, 50);
                                if (event.oldIndex !== event.newIndex) $wire.reorderPhotos(this.sortable.toArray().map(Number));
                            },
                        });
                        this.$watch('selecting', (on) => this.sortable.option('disabled', on));
                    },
                    destroy() { this.sortable?.destroy(); if (this.open !== null) this.close() },
                    // While the viewer is open, a sideways swipe changes photo instead of going back a page (Chrome).
                    show(i) { this.open = i; document.body.classList.add('overflow-hidden'); document.documentElement.style.overscrollBehaviorX = 'none' },
                    close() { this.open = null; document.body.classList.remove('overflow-hidden'); document.documentElement.style.overscrollBehaviorX = '' },
                    next() { this.open = (this.open + 1) % this.items.length },
                    prev() { this.open = (this.open - 1 + this.items.length) % this.items.length },
                    act(action) { const id = this.items[this.open].id; this.close(); $wire.mountAction(action, { media: id }) },
                    tap(i, id) { if (this.dragging) return; this.selecting ? this.toggle(id) : this.show(i) },
                    toggle(id) { this.selected.includes(id) ? this.selected = this.selected.filter((x) => x !== id) : this.selected.push(id) },
                    stopSelecting() { this.selecting = false; this.selected = [] },
                    swipe(e) { if (this.startX === null) return; const dx = e.changedTouches[0].clientX - this.startX; if (Math.abs(dx) > 40) dx < 0 ? this.next() : this.prev(); this.startX = null },
                }"
                x-on:keydown.escape.window="open !== null ? close() : stopSelecting()"
                x-on:keydown.arrow-right.window="open !== null && next()"
                x-on:keydown.arrow-left.window="open !== null && prev()"
                class="grid gap-4"
            >
                {{-- Add, or select several to delete --}}
                <div class="flex items-center justify-between gap-2">
                    <div x-show="! selecting">{{ $this->addPhotosAction }}</div>
                    <p x-show="selecting" x-cloak class="text-sm font-medium text-gray-700 dark:text-gray-200"
                       x-text="selected.length ? @js(__(':count selected')).replace(':count', selected.length) : @js(__('Tap photos to select them'))"></p>
                    <div class="flex items-center gap-2">
                        <x-filament::button x-show="selecting" x-cloak color="danger" icon="heroicon-o-trash" x-bind:disabled="! selected.length"
                                            x-on:click="$wire.mountAction('deletePhotos', { ids: selected })">
                            {{ __('Delete') }}
                        </x-filament::button>
                        <x-filament::button color="gray" x-on:click="selecting ? stopSelecting() : selecting = true">
                            <span x-text="selecting ? @js(__('Cancel')) : @js(__('Select'))"></span>
                        </x-filament::button>
                    </div>
                </div>

                <div x-ref="grid" class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5 2xl:grid-cols-6">
                    @foreach ($items as $i => $item)
                        <button type="button" data-id="{{ $item['id'] }}" x-on:click="tap({{ $i }}, {{ $item['id'] }})"
                                class="group relative aspect-square touch-manipulation select-none overflow-hidden rounded-xl bg-gray-100 ring-1 ring-gray-950/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-white/5 dark:ring-white/10"
                                x-bind:class="selecting && selected.includes({{ $item['id'] }}) && 'ring-2 ring-primary-500 dark:ring-primary-400'"
                                style="-webkit-touch-callout: none">
                            <img src="{{ $item['thumb'] }}" alt="" loading="lazy" draggable="false" class="pointer-events-none h-full w-full object-cover transition group-hover:scale-105"
                                 x-bind:class="selecting && selected.includes({{ $item['id'] }}) && 'opacity-75'">
                            @if ($i === 0)
                                <span class="absolute bottom-1.5 start-1.5 inline-flex items-center gap-1 rounded-md bg-black/65 px-1.5 py-0.5 text-[11px] font-semibold text-white backdrop-blur">
                                    <x-filament::icon icon="heroicon-m-star" class="size-3" /> {{ __('Cover') }}
                                </span>
                            @endif
                            {{-- Selection mark --}}
                            <span x-show="selecting" x-cloak
                                  class="absolute end-1.5 top-1.5 flex size-6 items-center justify-center rounded-full ring-2 ring-white"
                                  x-bind:class="selected.includes({{ $item['id'] }}) ? 'bg-primary-500 text-white' : 'bg-black/30'">
                                <x-filament::icon icon="heroicon-m-check" class="size-4" x-show="selected.includes({{ $item['id'] }})" />
                            </span>
                        </button>
                    @endforeach
                </div>

                {{-- Full-screen viewer --}}
                <template x-teleport="body">
                    <div x-show="open !== null" x-transition.opacity x-cloak
                         class="fixed inset-0 z-50 flex flex-col bg-gray-950/95"
                         style="padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); touch-action: pan-y pinch-zoom; overscroll-behavior: contain"
                         x-on:touchstart="startX = $event.touches[0].clientX" x-on:touchend="swipe($event)">
                        <div class="flex items-center gap-2 p-3 text-white">
                            <span class="min-w-0 flex-1 truncate text-sm" x-text="open !== null ? items[open].name : ''"></span>
                            <span class="shrink-0 text-xs text-white/60" x-text="open !== null ? (open + 1) + ' / ' + items.length : ''"></span>
                            <button type="button" x-on:click="close()" class="shrink-0 rounded-lg p-2 hover:bg-white/10" aria-label="{{ __('Close') }}">
                                <x-filament::icon icon="heroicon-o-x-mark" class="size-6" />
                            </button>
                        </div>

                        <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden px-2" x-on:click.self="close()">
                            <img :src="open !== null ? items[open].src : ''" alt="" class="max-h-full max-w-full rounded-lg object-contain">
                            <button type="button" x-show="items.length > 1" x-on:click="prev()" class="absolute start-2 hidden rounded-full bg-white/10 p-2 text-white hover:bg-white/20 sm:block" aria-label="{{ __('Previous') }}">
                                <x-filament::icon icon="heroicon-o-chevron-left" class="size-6" />
                            </button>
                            <button type="button" x-show="items.length > 1" x-on:click="next()" class="absolute end-2 hidden rounded-full bg-white/10 p-2 text-white hover:bg-white/20 sm:block" aria-label="{{ __('Next') }}">
                                <x-filament::icon icon="heroicon-o-chevron-right" class="size-6" />
                            </button>
                        </div>

                        <div class="grid grid-cols-4 gap-1 p-3 text-xs font-medium text-white sm:mx-auto sm:w-full sm:max-w-md">
                            <button type="button" x-on:click="act('makeCover')" x-bind:disabled="open === 0" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                <x-filament::icon icon="heroicon-o-star" class="size-6" />
                                <span x-text="open === 0 ? @js(__('Cover')) : @js(__('Make cover'))"></span>
                            </button>
                            <button type="button" x-on:click="act('renamePhoto')" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10">
                                <x-filament::icon icon="heroicon-o-pencil-square" class="size-6" />
                                {{ __('Rename') }}
                            </button>
                            <a x-bind:href="open !== null ? items[open].download : '#'" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10">
                                <x-filament::icon icon="heroicon-o-arrow-down-tray" class="size-6" />
                                {{ __('Download') }}
                            </a>
                            <button type="button" x-on:click="act('deletePhoto')" class="flex flex-col items-center gap-1 rounded-xl py-2 text-danger-400 hover:bg-white/10">
                                <x-filament::icon icon="heroicon-o-trash" class="size-6" />
                                {{ __('Delete') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
