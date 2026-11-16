{{-- Bottom tab bar for phones: one-handed navigation like a native app. --}}
<nav class="tpms-tab-bar fixed inset-x-0 bottom-0 z-30 border-t border-gray-950/5 bg-white/85 backdrop-blur-xl backdrop-saturate-150 lg:hidden dark:border-white/10 dark:bg-gray-900/85"
     style="padding-bottom: env(safe-area-inset-bottom)"
     aria-label="{{ __('Main') }}">
    <div class="mx-auto flex max-w-lg items-stretch justify-around px-2">
        @foreach (\App\Support\TabBar::items() as $item)
            <a href="{{ $item['url'] }}" wire:navigate @if ($item['active']) aria-current="page" @endif
               @class([
                   'flex min-w-0 flex-1 flex-col items-center gap-0.5 pb-1.5 pt-2 text-[11px] font-medium transition active:scale-95',
                   'text-primary-700 dark:text-primary-400' => $item['active'],
                   'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! $item['active'],
               ])>
                <x-filament::icon :icon="$item['active'] ? $item['active_icon'] : $item['icon']" class="h-6 w-6" />
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach

        {{-- Opens the menu, and closes it again while it is open. --}}
        <button type="button" x-data x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
                x-bind:aria-expanded="$store.sidebar.isOpen"
                class="flex min-w-0 flex-1 flex-col items-center gap-0.5 pb-1.5 pt-2 text-[11px] font-medium text-gray-500 transition hover:text-gray-700 active:scale-95 dark:text-gray-400 dark:hover:text-gray-200">
            <x-filament::icon icon="heroicon-o-bars-3" class="h-6 w-6" x-show="! $store.sidebar.isOpen" />
            <x-filament::icon icon="heroicon-o-x-mark" class="h-6 w-6" x-show="$store.sidebar.isOpen" x-cloak />
            <span class="truncate" x-text="$store.sidebar.isOpen ? @js(__('Close')) : @js(__('More'))">{{ __('More') }}</span>
        </button>
    </div>
</nav>
