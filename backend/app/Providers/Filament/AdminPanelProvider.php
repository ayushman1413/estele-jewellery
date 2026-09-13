<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Widgets\OldJewelleryStatsWidget;
use App\Filament\Widgets\StoreStatsWidget;
use App\Filament\Widgets\VendorPerformanceWidget;
use App\Http\Middleware\SecurityHeaders;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        FilamentTimezone::set('Asia/Kolkata');
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            // Publishes filament-shield's own RoleResource with a local fix
            // (see App\Filament\Resources\Roles\RoleResource's docblock) —
            // registering a resource whose class name ends in \RoleResource
            // makes Utils::isResourcePublished() skip the plugin's default.
            // MUST run before ->plugins(): Panel::plugin() calls the
            // plugin's register() immediately (not deferred), so
            // isResourcePublished()'s check of $panel->getResources() only
            // sees this resource if it was registered first — otherwise
            // both the vendor and the published resource end up
            // registered, doubling the "Roles" nav item.
            ->resources([
                RoleResource::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->navigationGroups([
                'Sell Jewellery',
                'Sales',
                'Wallet',
                'Content',
                'Team',
            ])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                StoreStatsWidget::class,
                OldJewelleryStatsWidget::class,
                VendorPerformanceWidget::class,
            ])
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
                SecurityHeaders::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
