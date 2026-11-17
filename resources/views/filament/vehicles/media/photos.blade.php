@php
    $photos = $this->photos();
    $items = $photos->map(fn ($m) => [
        'id' => $m->getKey(),
        'src' => $m->getUrl('preview'),
        'thumb' => $m->getUrl('thumb'),
        'download' => $m->getUrl().(str_contains($m->getUrl(), '?') ? '&' : '?').'download=1',
        'name' => $m->name,
        'editable' => $this::canEdit($m),
    ])->values();
    $ratios = [__('Free') => null, __('Garage card') => 16 / 10, __('Square') => 1, '4:3' => 4 / 3, '16:9' => 16 / 9];
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
                        // Back from the editor: show the edited photo.
                        const edited = this.items.findIndex((item) => item.id === @js($this->photoToShow()));
                        if (edited >= 0) this.show(edited);
                    },
                    destroy() { this.sortable?.destroy(); this.cropper?.destroy(); if (this.open !== null) this.close() },
                    // While the viewer is open, a sideways swipe changes photo instead of going back a page (Chrome).
                    show(i) { this.open = i; document.body.classList.add('overflow-hidden'); document.documentElement.style.overscrollBehaviorX = 'none' },
                    close() { this.open = null; document.body.classList.remove('overflow-hidden'); document.documentElement.style.overscrollBehaviorX = '' },
                    next() { this.open = (this.open + 1) % this.items.length },
                    prev() { this.open = (this.open - 1 + this.items.length) % this.items.length },
                    act(action) { const id = this.items[this.open].id; this.close(); $wire.mountAction(action, { media: id }) },
                    tap(i, id) { if (this.dragging) return; this.selecting ? this.toggle(id) : this.show(i) },
                    toggle(id) { this.selected.includes(id) ? this.selected = this.selected.filter((x) => x !== id) : this.selected.push(id) },
                    stopSelecting() { this.selecting = false; this.selected = [] },
                    swipe(e) { if (this.startX === null || this.editing) return; const dx = e.changedTouches[0].clientX - this.startX; if (Math.abs(dx) > 40) dx < 0 ? this.next() : this.prev(); this.startX = null },

                    // Editor (Cropper.js): works on the preview; the server repeats it on the original.
                    editing: false,
                    editorReady: false,
                    saving: false,
                    ratio: null,
                    cropper: null,
                    untouched: null,
                    async edit() {
                        this.editing = true;
                        this.editorReady = false;
                        this.ratio = null;
                        try {
                            if (! window.Cropper) await import(@js(\Illuminate\Support\Facades\Vite::asset('resources/js/photo-editor.js')));
                        } catch {
                            this.stopEditing();
                            new FilamentNotification().title(@js(__('The editor could not be loaded'))).body(@js(__('Check your connection and try again.'))).danger().send();
                            return;
                        }
                        // Cropper.js sizes itself to the space it gets, and x-show reveals the editor a moment later.
                        const area = this.$refs.editImage.parentNode;
                        while (! area.offsetHeight) {
                            await new Promise((resolve) => requestAnimationFrame(resolve));
                            if (! this.editing) return;
                        }
                        this.$refs.editImage.src = this.items[this.open].src;
                        this.cropper = new window.Cropper(this.$refs.editImage, {
                            viewMode: 1,
                            dragMode: 'move',
                            autoCropArea: 1,
                            background: false,
                            restore: false,
                            toggleDragModeOnDblclick: false,
                            // The browser already turns photos upright, like the server does.
                            checkOrientation: false,
                            ready: () => { this.untouched = JSON.stringify(this.cropper.getData(true)); this.editorReady = true },
                        });
                    },
                    stopEditing() {
                        this.cropper?.destroy();
                        this.cropper = null;
                        this.$refs.editImage.removeAttribute('src');
                        this.editing = this.saving = false;
                    },
                    setRatio(ratio) { this.ratio = ratio; this.cropper.setAspectRatio(ratio ?? NaN) },
                    rotate(degrees) {
                        const cropper = this.cropper;
                        // The photo can't be smaller than the crop box, so that goes while the photo is turned and fitted.
                        cropper.clear();
                        cropper.rotate(degrees);
                        // Fit the turned photo back into the editor and select as much of it as the shape allows.
                        const box = cropper.getContainerData(), canvas = cropper.getCanvasData();
                        const fit = Math.min(box.width / canvas.naturalWidth, box.height / canvas.naturalHeight);
                        const width = canvas.naturalWidth * fit, height = canvas.naturalHeight * fit;
                        const left = (box.width - width) / 2, top = (box.height - height) / 2;
                        cropper.setCanvasData({ left, top, width });
                        cropper.crop();
                        let cropWidth = width, cropHeight = height;
                        if (this.ratio && width / height > this.ratio) cropWidth = height * this.ratio;
                        else if (this.ratio) cropHeight = width / this.ratio;
                        cropper.setCropBoxData({ left: left + (width - cropWidth) / 2, top: top + (height - cropHeight) / 2, width: cropWidth, height: cropHeight });
                    },
                    // Mirrors left to right as the photo is shown, also when it is turned on its side.
                    flip() { const data = this.cropper.getData(); Math.abs(data.rotate) % 180 === 90 ? this.cropper.scaleY(-data.scaleY) : this.cropper.scaleX(-data.scaleX) },
                    resetEdit() { this.setRatio(null); this.cropper.reset() },
                    async save() {
                        const data = this.cropper.getData(true);
                        if (JSON.stringify(data) === this.untouched) return this.stopEditing();
                        const image = this.cropper.getImageData();
                        this.saving = true;
                        // On success the gallery is rebuilt and opens on the edited photo.
                        await $wire.editPhoto(this.items[this.open].id, { ...data, naturalWidth: image.naturalWidth, naturalHeight: image.naturalHeight });
                        this.saving = false;
                    },
                }"
                x-on:keydown.escape.window="editing ? stopEditing() : (open !== null ? close() : stopSelecting())"
                x-on:keydown.arrow-right.window="open !== null && ! editing && next()"
                x-on:keydown.arrow-left.window="open !== null && ! editing && prev()"
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

                {{-- Full-screen viewer: over the bars (z-30), under notifications (z-50), so "Photo saved" shows on top. --}}
                <template x-teleport="body">
                    <div x-show="open !== null" x-transition.opacity x-cloak
                         class="fixed inset-0 z-40 flex flex-col bg-gray-950/95"
                         style="padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); touch-action: pan-y pinch-zoom; overscroll-behavior: contain"
                         x-on:touchstart="startX = editing ? null : $event.touches[0].clientX" x-on:touchend="swipe($event)">
                        <div x-show="! editing" class="flex items-center gap-2 p-3 text-white">
                            <span class="min-w-0 flex-1 truncate text-sm" x-text="open !== null ? items[open].name : ''"></span>
                            <span class="shrink-0 text-xs text-white/60" x-text="open !== null ? (open + 1) + ' / ' + items.length : ''"></span>
                            <button type="button" x-on:click="close()" class="shrink-0 rounded-lg p-2 hover:bg-white/10" aria-label="{{ __('Close') }}">
                                <x-filament::icon icon="heroicon-o-x-mark" class="size-6" />
                            </button>
                        </div>

                        <div x-show="! editing" class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden px-2" x-on:click.self="close()">
                            <img :src="open !== null ? items[open].src : ''" alt="" class="max-h-full max-w-full rounded-lg object-contain">
                            <button type="button" x-show="items.length > 1" x-on:click="prev()" class="absolute start-2 hidden rounded-full bg-white/10 p-2 text-white hover:bg-white/20 sm:block" aria-label="{{ __('Previous') }}">
                                <x-filament::icon icon="heroicon-o-chevron-left" class="size-6" />
                            </button>
                            <button type="button" x-show="items.length > 1" x-on:click="next()" class="absolute end-2 hidden rounded-full bg-white/10 p-2 text-white hover:bg-white/20 sm:block" aria-label="{{ __('Next') }}">
                                <x-filament::icon icon="heroicon-o-chevron-right" class="size-6" />
                            </button>
                        </div>

                        <div x-show="! editing" class="grid grid-cols-5 gap-1 p-3 text-xs font-medium text-white sm:mx-auto sm:w-full sm:max-w-lg">
                            <button type="button" x-on:click="act('makeCover')" x-bind:disabled="open === 0" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                <x-filament::icon icon="heroicon-o-star" class="size-6" />
                                <span x-text="open === 0 ? @js(__('Cover')) : @js(__('Make cover'))"></span>
                            </button>
                            <button type="button" x-on:click="edit()" x-bind:disabled="open === null || ! items[open].editable"
                                    x-bind:title="open !== null && ! items[open].editable ? @js(__('Photos in this format can’t be edited')) : null"
                                    class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                <x-filament::icon icon="tpms-crop" class="size-6" />
                                {{ __('Edit') }}
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

                        {{-- Editor: crop, turn, flip --}}
                        <div x-show="editing" x-cloak class="flex min-h-0 flex-1 flex-col text-white">
                            <div class="flex items-center gap-2 p-3">
                                <button type="button" x-on:click="stopEditing()" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-white/10">
                                    {{ __('Cancel') }}
                                </button>
                                <span class="min-w-0 flex-1 truncate text-center text-sm font-semibold">{{ __('Edit photo') }}</span>
                                <button type="button" x-on:click="save()" x-bind:disabled="! editorReady || saving"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold hover:bg-primary-500 disabled:opacity-50">
                                    <x-filament::loading-indicator x-show="saving" x-cloak class="size-4" />
                                    {{ __('Save') }}
                                </button>
                            </div>

                            {{-- Cropper.js sizes itself to the image's parent, so that has no padding. While it builds,
                                 the full-size image would stick out and make a phone browser zoom out: clip it. --}}
                            <div class="min-h-0 flex-1 overflow-hidden px-2">
                                <div class="relative h-full overflow-hidden">
                                    <img x-ref="editImage" alt="" class="block max-w-full">
                                    <div x-show="! editorReady" class="absolute inset-0 flex items-center justify-center text-white/70">
                                        <x-filament::loading-indicator class="size-8" />
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-1.5 overflow-x-auto px-3 pt-3 sm:justify-center" style="scrollbar-width: none">
                                @foreach ($ratios as $label => $ratio)
                                    <button type="button" x-on:click="setRatio(@js($ratio))" x-bind:disabled="! editorReady"
                                            class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold transition"
                                            x-bind:class="ratio === @js($ratio) ? 'bg-white text-gray-950' : 'bg-white/10 text-white hover:bg-white/20'"
                                            @if ($ratio === 16 / 10) title="{{ __('The shape of the photo on the garage card') }}" @endif>
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>

                            <div class="grid grid-cols-4 gap-1 p-3 text-xs font-medium sm:mx-auto sm:w-full sm:max-w-md">
                                <button type="button" x-on:click="rotate(-90)" x-bind:disabled="! editorReady" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                    <x-filament::icon icon="tpms-rotate-left" class="size-6" />
                                    {{ __('Turn left') }}
                                </button>
                                <button type="button" x-on:click="rotate(90)" x-bind:disabled="! editorReady" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                    <x-filament::icon icon="tpms-rotate-right" class="size-6" />
                                    {{ __('Turn right') }}
                                </button>
                                <button type="button" x-on:click="flip()" x-bind:disabled="! editorReady" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                    <x-filament::icon icon="tpms-flip" class="size-6" />
                                    {{ __('Mirror') }}
                                </button>
                                <button type="button" x-on:click="resetEdit()" x-bind:disabled="! editorReady" class="flex flex-col items-center gap-1 rounded-xl py-2 hover:bg-white/10 disabled:opacity-40">
                                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-6" />
                                    {{ __('Undo all') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
