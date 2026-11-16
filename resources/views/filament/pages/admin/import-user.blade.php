{{-- A LubeLogger user: who they are and which vehicles they get. --}}
<div class="flex items-start gap-3">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold uppercase text-gray-600 dark:bg-white/10 dark:text-gray-300">
        {{ mb_substr($user['username'], 0, 1) }}
    </span>
    <div class="min-w-0">
        <p class="flex flex-wrap items-center gap-1.5 font-medium text-gray-950 dark:text-white">
            {{ $user['username'] }}
            @if ($user['is_admin'])<x-filament::badge size="sm" icon="heroicon-m-shield-check">{{ __('Admin') }}</x-filament::badge>@endif
        </p>
        <p class="truncate text-sm text-gray-500 dark:text-gray-400">
            {{ collect([$user['email'], $user['vehicles'] ? implode(', ', $user['vehicles']) : __('No vehicles')])->filter()->implode(' · ') }}
        </p>
    </div>
</div>
