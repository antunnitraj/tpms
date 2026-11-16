<x-filament-panels::page.simple>
    @if ($created)
        <div class="grid grid-cols-1 gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Upload the ZIP from LubeLogger (Settings → Backups → Make Backup). You will see a preview of every vehicle and user before anything is imported.') }}
            </p>
            {{ $this->importAction }}
            {{ $this->skipAction }}
        </div>
    @else
        <form wire:submit="create" x-data x-init="$wire.useBrowserTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone ?? '')">
            {{ $this->form }}
        </form>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page.simple>
