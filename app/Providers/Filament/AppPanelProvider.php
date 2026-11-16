<?php

namespace App\Providers\Filament;

use App\Enums\Theme;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Setup;
use App\Http\Middleware\ApplyUserTheme;
use App\Http\Middleware\EnsureSetupComplete;
use App\Support\AppIcon;
use App\Support\TabBar;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('The Pocket Mechanic')
            ->brandLogo(fn () => view('filament.brand'))
            ->favicon(fn () => AppIcon::url('icon.svg'))
            ->colors(Theme::DEFAULT->colors())
            ->spa()
            // Each action saves in one transaction, attachments and supply usage included:
            // a failure leaves nothing half-saved for a retry to duplicate.
            ->databaseTransactions()
            // On a page left open after its vehicle was deleted or unshared (by someone else, or in another tab),
            // "try again later" never helps: say what happened instead.
            ->registerErrorNotification(
                fn () => __('This is no longer available'),
                fn () => __('It was deleted, or it isn\'t shared with you any more. Go back to the garage to carry on.'),
                403,
            )
            ->registerErrorNotification(
                fn () => __('This is no longer available'),
                fn () => __('It was deleted, or it isn\'t shared with you any more. Go back to the garage to carry on.'),
                404,
            )
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::SevenExtraLarge)
            ->navigationGroups([
                NavigationGroup::make(__('Administration')),
            ])
            ->viteTheme('resources/css/filament/app/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->routes(fn () => Route::get('/setup', Setup::class)->name('setup'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('pwa.head'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => TabBar::shouldShow() ? view('filament.tab-bar') : '')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                EnsureSetupComplete::class,
            ])
            ->middleware([ApplyUserTheme::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
