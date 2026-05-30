<?php

namespace App\Providers\Filament;

use App\Filament\Resources\DataDictionaryEntries\DataDictionaryEntryResource;
use App\Filament\Resources\GeoCountries\GeoCountryResource;
use App\Http\Middleware\TrackAdminUserActivity;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament as FilamentFacade;
use Filament\Forms\Components\TextInput;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('AB Connector')
            ->globalSearch(false)
            ->favicon(fn (): string => asset(match (app()->environment()) {
                'local' => 'favicons/favicon-local.svg',
                'staging' => 'favicons/favicon-staging.svg',
                default => 'favicons/favicon-production.svg',
            }))
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('fi-admin-content-wide')
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.components.admin-theme-overrides')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_LOGO_AFTER,
                fn (): string => '',
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): string => '',
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_LOGO_AFTER,
                fn (): string => view('filament.components.admin-topbar-start')->render(),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => view('filament.components.admin-topbar-end')->render(),
            )
            ->navigationGroups([
                'Аудитория',
                'Аналитика',
                'Автоматизация',
                'Команда',
                'Настройки',
            ])
            ->navigationItems([
                NavigationItem::make('Справочники')
                    ->group('Настройки')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->url(fn (): string => DataDictionaryEntryResource::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.data-dictionary-entries.*'))
                    ->sort(18),
                NavigationItem::make('Адреса')
                    ->group('Настройки')
                    ->parentItem('Справочники')
                    ->icon(Heroicon::OutlinedGlobeEuropeAfrica)
                    ->url(fn (): string => GeoCountryResource::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.geo-*'))
                    ->sort(2),
            ])
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action->sort(-2),
                Action::make('editProfile')
                    ->label('Редактировать профиль')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->sort(-1)
                    ->modalHeading('Редактировать профиль')
                    ->modalDescription('Измените имя и фамилию, которые видны в админке.')
                    ->modalSubmitActionLabel('Сохранить')
                    ->modalWidth(Width::Medium)
                    ->fillForm(fn (): array => [
                        'name' => auth()->user()?->name,
                        'last_name' => auth()->user()?->last_name,
                    ])
                    ->form([
                        TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->label('Фамилия')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim((string) $state) : null)
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        $user->fill([
                            'name' => $data['name'],
                            'last_name' => $data['last_name'] ?? null,
                        ]);

                        $user->save();
                    })
                    ->successNotificationTitle('Профиль обновлён')
                    ->successRedirectUrl(fn (): string => request()->headers->get('referer') ?? FilamentFacade::getUrl()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                TrackAdminUserActivity::class,
            ]);
    }
}
