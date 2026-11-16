{{-- Installable web app: manifest, icons, status bar and service worker. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ \Filament\Support\Colors\Color::convertToHex(\Filament\Support\Facades\FilamentColor::getColor('gray')[900]) }}" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
{{-- Short label under the home-screen icon; the full name would be cut off there. --}}
<meta name="apple-mobile-web-app-title" content="TPMS">
<meta name="application-name" content="The Pocket Mechanic">
<link rel="apple-touch-icon" href="{{ \App\Support\AppIcon::url('apple-touch-icon.png') }}">
<link rel="icon" type="image/svg+xml" href="{{ \App\Support\AppIcon::url('icon.svg') }}">
@php
    $theme = auth()->user()?->theme() ?? \App\Enums\Theme::DEFAULT;
@endphp
<style>
    /* Solid accent fills (e.g. the phone "+" button): deep accents carry white text, bright ones dark text. */
    :root {
        --tpms-solid-bg: var(--primary-{{ $theme->isBright() ? 400 : 600 }});
        --tpms-solid-fg: {{ $theme->isBright() ? 'var(--gray-950)' : '#fff' }};
    }
    @if ($theme->isBright())
        /* Filament deepens bright accents to an orange-ish 600 in dark mode; keep them bright. */
        .fi-btn.fi-color-primary.dark\:fi-bg-color-600 { --dark-bg: var(--primary-400); --dark-hover-bg: var(--primary-300); }
    @endif
</style>
<script>
    // Filament's sidebar store is created on the login/setup pages (which have no
    // sidebar) and then reused by SPA navigation, so seed its persisted state.
    try {
        const key = 'filament:sidebar:collapsed_groups';
        const value = localStorage.getItem(key);
        if (value === null || value === 'null') {
            localStorage.setItem(key, '[]');
        }
    } catch (e) {}

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('{{ asset('sw.js') }}', { scope: '/' }).catch(() => {}));
    }
</script>
