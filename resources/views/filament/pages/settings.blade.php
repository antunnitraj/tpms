@php
    $current = auth()->user()->theme();
@endphp

<x-filament-panels::page>
    <x-filament::section :heading="__('Theme')" :description="__('Only changes how the app looks for you.')">
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
            @foreach (\App\Enums\Theme::cases() as $theme)
                @php
                    $selected = $theme === $current;
                    $p = $theme->primary();
                    $g = $theme->gray();
                @endphp
                <button
                    type="button"
                    wire:click="setTheme('{{ $theme->value }}')"
                    wire:loading.attr="disabled"
                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    class="group flex flex-col gap-2 rounded-2xl p-1.5 text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                >
                    {{-- A tiny screen in the theme's dark mode: top bar, a card with text and an accent button. --}}
                    <span
                        @class([
                            'relative block aspect-[4/3] w-full overflow-hidden rounded-xl ring-1 transition',
                            'ring-2 ring-primary-500 dark:ring-primary-400' => $selected,
                            'ring-gray-950/10 group-hover:ring-gray-950/25 dark:ring-white/10 dark:group-hover:ring-white/25' => ! $selected,
                        ])
                        style="background: {{ $g[950] }}"
                    >
                        <span class="absolute inset-x-0 top-0 h-[14%]" style="background: {{ $g[900] }}"></span>
                        <span class="absolute left-[10%] right-[10%] top-[24%] bottom-[12%] rounded-md" style="background: {{ $g[900] }}">
                            <span class="absolute left-[12%] top-[16%] h-[11%] w-[52%] rounded-full" style="background: {{ $g[500] }}"></span>
                            <span class="absolute left-[12%] top-[36%] h-[9%] w-[36%] rounded-full" style="background: {{ $g[700] }}"></span>
                            <span class="absolute bottom-[14%] left-[12%] h-[22%] w-[44%] rounded-full" style="background: {{ $theme->isBright() ? $p[400] : $p[500] }}"></span>
                            <span class="absolute bottom-[14%] right-[12%] size-[22%] rounded-full" style="background: {{ $p[400] }}; opacity: .35"></span>
                        </span>
                        @if ($selected)
                            <span class="absolute right-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-white text-gray-950 shadow">
                                <x-filament::icon icon="heroicon-m-check" class="size-3.5" />
                            </span>
                        @endif
                    </span>
                    <span class="flex items-center gap-1.5 px-0.5">
                        <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $p[500] }}"></span>
                        <span @class([
                            'truncate text-xs font-medium',
                            'text-gray-950 dark:text-white' => $selected,
                            'text-gray-600 dark:text-gray-300' => ! $selected,
                        ])>{{ $theme->getLabel() }}</span>
                    </span>
                </button>
            @endforeach
        </div>
    </x-filament::section>

    <form wire:submit="save" class="grid grid-cols-1 gap-6">
        {{ $this->form }}
        <div>
            <x-filament::button type="submit" class="w-full sm:w-auto">{{ __('Save') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
