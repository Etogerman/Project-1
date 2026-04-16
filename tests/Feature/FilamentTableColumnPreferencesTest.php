<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentTableColumnPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_schema_adds_table_preferences_to_users(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'table_preferences'));
    }

    public function test_reorderable_table_columns_are_persisted_for_the_current_user(): void
    {
        $admin = $this->createAdmin();
        $defaultState = $this->getDefaultTableState(ManageContacts::class, $admin);
        $customState = $this->buildCustomizedState($defaultState, hiddenColumnName: 'id', reorder: true);

        $this->applyTableState(ManageContacts::class, $admin, $customState, wasReordered: true);

        $preference = $admin->fresh()->getTablePreference(ManageContacts::class);

        $this->assertNotNull($preference);
        $this->assertTrue((bool) ($preference['has_reordered_columns'] ?? false));
        $this->assertSame($this->flattenTableColumnNames($customState), $this->flattenTableColumnNames($preference['columns'] ?? []));
        $this->assertFalse($this->findColumnState($preference['columns'] ?? [], 'id')['isToggled']);

        $mountedState = $this->getMountedTableState(ManageContacts::class, $admin);

        $this->assertSame($this->flattenTableColumnNames($customState), $this->flattenTableColumnNames($mountedState));
        $this->assertFalse($this->findColumnState($mountedState, 'id')['isToggled']);
    }

    public function test_table_column_preferences_are_scoped_per_user_and_page(): void
    {
        $firstAdmin = $this->createAdmin();
        $secondAdmin = $this->createAdmin();
        $defaultUsersState = $this->getDefaultTableState(ManageUsers::class, $firstAdmin);
        $customUsersState = $this->buildCustomizedState($defaultUsersState, hiddenColumnName: 'email');

        $this->applyTableState(ManageUsers::class, $firstAdmin, $customUsersState);

        $secondAdminUsersState = $this->getMountedTableState(ManageUsers::class, $secondAdmin);
        $firstAdminContactsState = $this->getMountedTableState(ManageContacts::class, $firstAdmin);

        $this->assertFalse($this->findColumnState($customUsersState, 'email')['isToggled']);
        $this->assertTrue($this->findColumnState($secondAdminUsersState, 'email')['isToggled']);
        $this->assertNull($secondAdmin->fresh()->getTablePreference(ManageUsers::class));
        $this->assertNull($firstAdmin->fresh()->getTablePreference(ManageContacts::class));
        $this->assertSame(
            $this->flattenTableColumnNames($this->getDefaultTableState(ManageContacts::class, $firstAdmin)),
            $this->flattenTableColumnNames($firstAdminContactsState),
        );
    }

    public function test_reset_table_column_manager_forgets_persisted_preference(): void
    {
        $admin = $this->createAdmin();
        $defaultState = $this->getDefaultTableState(ManageUsers::class, $admin);
        $customState = $this->buildCustomizedState($defaultState, hiddenColumnName: 'email');

        Livewire::actingAs($admin)
            ->test(ManageUsers::class)
            ->call('applyTableColumnManager', $customState, false)
            ->call('resetTableColumnManager');

        $this->assertNull($admin->fresh()->getTablePreference(ManageUsers::class));
        $this->assertTrue($this->findColumnState($this->getMountedTableState(ManageUsers::class, $admin), 'email')['isToggled']);
    }

    public function test_saved_table_column_preferences_ignore_unknown_columns_and_keep_new_defaults(): void
    {
        $admin = $this->createAdmin();
        $defaultState = $this->getDefaultTableState(ManageUsers::class, $admin);
        $nameColumn = $this->findColumnState($defaultState, 'name');
        $nameColumn['isToggled'] = false;

        $admin->putTablePreference(ManageUsers::class, [
            'columns' => [
                $nameColumn,
                [
                    'type' => 'column',
                    'name' => 'legacy_column',
                    'label' => 'Legacy column',
                    'isHidden' => false,
                    'isToggled' => false,
                    'isToggleable' => true,
                    'isToggledHiddenByDefault' => null,
                ],
            ],
            'has_reordered_columns' => false,
        ]);

        $mountedState = $this->getMountedTableState(ManageUsers::class, $admin);

        $this->assertSame(
            $this->flattenTableColumnNames($defaultState),
            $this->flattenTableColumnNames($mountedState),
        );
        $this->assertFalse($this->findColumnState($mountedState, 'name')['isToggled']);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultTableState(string $pageClass, User $user): array
    {
        $state = [];

        Livewire::actingAs($user)
            ->test($pageClass)
            ->tap(function ($component) use (&$state): void {
                $state = $component->instance()->getDefaultTableColumnState();
            });

        return $state;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function applyTableState(string $pageClass, User $user, array $state, bool $wasReordered = false): void
    {
        Livewire::actingAs($user)
            ->test($pageClass)
            ->call('applyTableColumnManager', $state, $wasReordered);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMountedTableState(string $pageClass, User $user): array
    {
        $state = [];

        Livewire::actingAs($user)
            ->test($pageClass)
            ->tap(function ($component) use (&$state): void {
                /** @var array<int, array<string, mixed>> $tableColumns */
                $tableColumns = $component->instance()->tableColumns;

                $state = $tableColumns;
            });

        return $state;
    }

    /**
     * @param  array<int, array<string, mixed>>  $defaultState
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomizedState(array $defaultState, string $hiddenColumnName, bool $reorder = false): array
    {
        $customState = array_map(function (array $item) use ($hiddenColumnName): array {
            if ($item['type'] === 'column' && $item['name'] === $hiddenColumnName) {
                $item['isToggled'] = false;
            }

            return $item;
        }, $defaultState);

        if ($reorder && count($customState) > 1) {
            $lastColumn = array_pop($customState);

            if (is_array($lastColumn)) {
                array_unshift($customState, $lastColumn);
            }
        }

        return $customState;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @return array<int, string>
     */
    private function flattenTableColumnNames(array $state): array
    {
        return array_map(
            static fn (array $item): string => (string) ($item['name'] ?? ''),
            $state,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @return array<string, mixed>
     */
    private function findColumnState(array $state, string $columnName): array
    {
        foreach ($state as $item) {
            if (($item['name'] ?? null) === $columnName) {
                return $item;
            }
        }

        $this->fail(sprintf('Column state [%s] was not found.', $columnName));
    }
}
