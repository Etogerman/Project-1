<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\RolePermissionMatrix as RolePermissionMatrixSupport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class RolePermissionMatrix extends Page
{
    protected string $view = 'filament.pages.role-permission-matrix';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Матрица прав';

    protected static string|UnitEnum|null $navigationGroup = 'Команда';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Матрица ролей и прав';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->canManageSystem();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Текущая модель доступа из кода без редактирования прав в интерфейсе.';
    }

    /**
     * @return array{
     *     roles: list<array{key:string,label:string,tone:string}>,
     *     groups: list<array{
     *         key:string,
     *         label:string,
     *         description:string,
     *         actions:list<array{
     *             code:string,
     *             label:string,
     *             description:string,
     *             states: array<string, array{allowed:bool,label:string,tone:string}>
     *         }>
     *     }>
     * }
     */
    protected function getViewData(): array
    {
        return app(RolePermissionMatrixSupport::class)->build();
    }
}
