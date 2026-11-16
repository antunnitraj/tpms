<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#f5f5f4" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#1c1917" media="(prefers-color-scheme: dark)">
        <title>@yield('title')</title>

        {{-- Self-contained: an error page can't rely on the app's assets. The installed app has no
             back button, so there is always a way home. --}}
        <style>
            :root { --bg: #f5f5f4; --card: #ffffff; --text: #1c1917; --muted: #78716c; --line: #e7e5e4; --accent: #1c1917; --accent-text: #ffffff; }
            @media (prefers-color-scheme: dark) {
                :root { --bg: #1c1917; --card: #292524; --text: #fafaf9; --muted: #a8a29e; --line: #44403c; --accent: #fafaf9; --accent-text: #1c1917; }
            }
            * { box-sizing: border-box; }
            body {
                margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
                padding: max(24px, env(safe-area-inset-top)) 16px max(24px, env(safe-area-inset-bottom));
                background: var(--bg); color: var(--text);
                font: 16px/1.5 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                -webkit-font-smoothing: antialiased;
            }
            main { width: 100%; max-width: 380px; padding: 32px 24px; border: 1px solid var(--line); border-radius: 24px; background: var(--card); text-align: center; }
            .code { margin: 0; color: var(--muted); font-size: 14px; font-weight: 600; letter-spacing: .08em; }
            h1 { margin: 8px 0 24px; font-size: 20px; font-weight: 600; }
            a { display: block; padding: 12px 16px; border-radius: 12px; background: var(--accent); color: var(--accent-text); font-weight: 600; text-decoration: none; }
        </style>
    </head>
    <body>
        <main>
            <p class="code">@yield('code')</p>
            <h1>@yield('message')</h1>
            <a href="{{ url('/') }}">{{ __('Back to the garage') }}</a>
        </main>
    </body>
</html>
