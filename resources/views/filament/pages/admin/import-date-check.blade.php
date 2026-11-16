{{-- How text dates from the backup will be read with the chosen format. --}}
<div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-white/5">
    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Date check') }}</p>
    <ul class="grid gap-1 sm:grid-cols-2">
        @foreach ($samples as $sample)
            <li class="flex items-center gap-2">
                <span class="font-mono text-gray-500 dark:text-gray-400">{{ $sample['raw'] }}</span>
                <x-filament::icon icon="heroicon-m-arrow-right" class="size-3.5 text-gray-400" />
                @if ($sample['parsed'])
                    <span class="font-medium text-gray-950 dark:text-white">{{ \Carbon\Carbon::parse($sample['parsed'])->toFormattedDateString() }}</span>
                @else
                    <span class="font-medium text-danger-600 dark:text-danger-400">{{ __('unreadable') }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</div>
