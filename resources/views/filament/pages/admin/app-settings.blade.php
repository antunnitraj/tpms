<x-filament-panels::page>
    <form wire:submit="save" class="grid grid-cols-1 gap-6">
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>
        <div>
            <x-filament::button type="submit" class="w-full sm:w-auto">{{ __('Save') }}</x-filament::button>
        </div>
    </form>

    <x-filament::section :heading="__('Danger zone')" icon="heroicon-o-exclamation-triangle" icon-color="danger" class="ring-danger-600/30 dark:ring-danger-400/30">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm text-gray-600 dark:text-gray-300">
                <p class="font-medium text-gray-950 dark:text-white">{{ __('Full reset') }}</p>
                <p class="mt-1">{{ __('Deletes all users, vehicles, entries, files and settings, and takes The Pocket Mechanic back to Setup.') }}</p>
            </div>
            <div class="shrink-0">{{ $this->resetAction }}</div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
