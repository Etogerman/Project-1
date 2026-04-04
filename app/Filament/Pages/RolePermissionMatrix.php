<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Users\UpdateRolePermissionMatrixAction;
use App\Support\RolePermissionMatrix as RolePermissionMatrixSupport;
use BackedEnum;
use Filament\Notifications\Notification;
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

    /**
     * @var array<string, array<string, array<string, bool>>>
     */
    public array $permissionState = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->reloadPermissionMatrix();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->canManageRolePermissionRecovery();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Страница recovery-доступа для суперадминистратора. Она управляет конфигурацией матрицы прав и остаётся доступной вне обычного role_permissions rollout.';
    }

    public function savePermissionMatrix(): void
    {
        $forcedAssignments = app(UpdateRolePermissionMatrixAction::class)->handle($this->permissionState);

        $this->reloadPermissionMatrix();

        Notification::make()
            ->success()
            ->title('Матрица прав сохранена')
            ->body(
                empty($forcedAssignments)
                    ? 'Изменения записаны в таблицу role_permissions.'
                    : 'Изменения записаны в таблицу role_permissions. Критичные права администратора сохранены включёнными.',
            )
            ->send();
    }

    public function reloadPermissionMatrix(): void
    {
        $this->permissionState = app(RolePermissionMatrixSupport::class)->editableState();
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
     *             isRuntimeActive: bool,
     *             runtimeStatus: string,
     *             runtimeLabel: string,
     *             runtimeDescription: string,
     *             runtimeTone: string,
     *             isPreparatory: bool,
     *             preparatoryLabel: ?string,
     *             preparatoryDescription: ?string,
     *             states: array<string, array{
     *                 allowed:bool,
     *                 label:string,
     *                 tone:string,
     *                 status:string,
     *                 editable:bool,
     *                 lockReason:?string
     *             }>
     *         }>
     *     }>
     * }
     */
    protected function getViewData(): array
    {
        return app(RolePermissionMatrixSupport::class)->build($this->permissionState);
    }
}
