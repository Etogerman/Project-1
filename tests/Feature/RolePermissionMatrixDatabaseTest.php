<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionMatrixDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_reads_role_permission_values_from_database(): void
    {
        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'contacts.view')
            ->update(['granted' => false]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'bitrix24.edit')
            ->update(['granted' => true]);

        $matrix = app(RolePermissionMatrix::class)->build();

        $this->assertSame(
            'disabled',
            $this->stateFor($matrix, 'contacts.view', User::ROLE_EMPLOYEE)['status'],
        );

        $this->assertSame(
            'Выключено',
            $this->stateFor($matrix, 'contacts.view', User::ROLE_EMPLOYEE)['label'],
        );

        $this->assertSame(
            'enabled',
            $this->stateFor($matrix, 'bitrix24.edit', User::ROLE_EMPLOYEE)['status'],
        );

        $this->assertSame(
            'Включено',
            $this->stateFor($matrix, 'bitrix24.edit', User::ROLE_EMPLOYEE)['label'],
        );
    }

    public function test_matrix_marks_protected_admin_assignments_as_non_editable(): void
    {
        $matrix = app(RolePermissionMatrix::class)->build();

        $viewState = $this->stateFor($matrix, 'users.view', User::ROLE_ADMIN);
        $editState = $this->stateFor($matrix, 'users.edit', User::ROLE_ADMIN);

        $this->assertFalse($viewState['editable']);
        $this->assertFalse($editState['editable']);
        $this->assertNotNull($viewState['lockReason']);
        $this->assertNotNull($editState['lockReason']);
    }

    public function test_matrix_exposes_runtime_rollout_status_per_action(): void
    {
        $matrix = app(RolePermissionMatrix::class)->build();

        $runtimeActiveAction = $this->actionFor($matrix, 'users.view');
        $contactsAction = $this->actionFor($matrix, 'contacts.view');

        $this->assertTrue($runtimeActiveAction['isRuntimeActive']);
        $this->assertSame('runtime-active', $runtimeActiveAction['runtimeStatus']);
        $this->assertSame('Уже влияет на доступ', $runtimeActiveAction['runtimeLabel']);

        $this->assertTrue($contactsAction['isRuntimeActive']);
        $this->assertSame('runtime-active', $contactsAction['runtimeStatus']);
        $this->assertSame('Уже влияет на доступ', $contactsAction['runtimeLabel']);
    }

    public function test_matrix_marks_missing_database_rows_as_configuration_issue(): void
    {
        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.edit')
            ->delete();

        $matrix = app(RolePermissionMatrix::class)->build();
        $state = $this->stateFor($matrix, 'dialogs.edit', User::ROLE_EMPLOYEE);

        $this->assertSame('missing', $state['status']);
        $this->assertSame('Нет записи', $state['label']);
        $this->assertSame('warning', $state['tone']);
        $this->assertFalse($state['allowed']);
    }

    /**
     * @param  array{
     *     groups: list<array{
     *         actions:list<array{
     *             code:string,
     *             isRuntimeActive: bool,
     *             runtimeStatus: string,
     *             runtimeLabel: string,
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
     * }  $matrix
     * @return array{allowed:bool,label:string,tone:string,status:string,editable:bool,lockReason:?string}
     */
    private function stateFor(array $matrix, string $code, string $role): array
    {
        $action = $this->actionFor($matrix, $code);

        return $action['states'][$role];
    }

    /**
     * @param  array{
     *     groups: list<array{
     *         actions:list<array{
     *             code:string,
     *             isRuntimeActive: bool,
     *             runtimeStatus: string,
     *             runtimeLabel: string,
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
     * }  $matrix
     * @return array{
     *     code:string,
     *     isRuntimeActive: bool,
     *     runtimeStatus: string,
     *     runtimeLabel: string,
     *     states: array<string, array{
     *         allowed:bool,
     *         label:string,
     *         tone:string,
     *         status:string,
     *         editable:bool,
     *         lockReason:?string
     *     }>
     * }
     */
    private function actionFor(array $matrix, string $code): array
    {
        $action = collect($matrix['groups'])
            ->pluck('actions')
            ->flatten(1)
            ->firstWhere('code', $code);

        $this->assertIsArray($action);

        return $action;
    }
}
