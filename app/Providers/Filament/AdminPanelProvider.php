<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // 管理画面は APP_URL (= 本番は admin.example.com) のドメインに限定。
        // 公開HP ドメイン (example.com) から /admin/ 等を叩かれても 404 にして、
        // Stripe webhook 等の request host 混同を根本的に防ぐ。
        // 空ならドメイン制限なし (ローカル dev 用)。
        $adminDomain = parse_url((string) config('app.url'), PHP_URL_HOST);

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('/');

        if (is_string($adminDomain) && $adminDomain !== '' && $adminDomain !== 'localhost') {
            $panel->domain($adminDomain);
        }

        return $panel
            ->login()
            ->brandName('direct')
            ->brandLogo(asset('favicon.svg'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::Teal,
            ])
            ->font('Noto Sans JP')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
