<?php

namespace Tests\Feature;

use App\Support\RolePermissionCatalog;
use Tests\TestCase;

class RolePermissionCatalogTest extends TestCase
{
    public function test_catalog_contains_agreed_large_permission_keys(): void
    {
        $catalog = app(RolePermissionCatalog::class)->groups();

        $codes = collect($catalog)
            ->pluck('actions')
            ->flatten(1)
            ->pluck('code')
            ->values()
            ->all();

        $this->assertSame([
            'contacts.view',
            'contacts.edit',
            'contacts.delete',
            'dialogs.view',
            'dialogs.edit',
            'tags.view',
            'tags.edit',
            'tags.delete',
            'channels.view',
            'channels.edit',
            'auto_reply_rules.view',
            'auto_reply_rules.edit',
            'auto_reply_rules.delete',
            'bitrix24.view',
            'bitrix24.edit',
            'analytics.view',
            'analytics.debug',
            'scenarios.view',
            'scenarios.edit',
            'users.view',
            'users.edit',
        ], $codes);
    }

    public function test_catalog_does_not_expose_granular_legacy_or_hidden_preparatory_rows(): void
    {
        $catalog = app(RolePermissionCatalog::class)->groups();

        $codes = collect($catalog)
            ->pluck('actions')
            ->flatten(1)
            ->pluck('code')
            ->values()
            ->all();

        $this->assertNotContains('contacts.phone.edit_existing', $codes);
        $this->assertNotContains('contacts.assignee.assign', $codes);
        $this->assertNotContains('dialogs.delete', $codes);
        $this->assertNotContains('users.delete', $codes);
        $this->assertNotContains('channels.delete', $codes);
        $this->assertNotContains('bitrix24.delete', $codes);
        $this->assertNotContains('scenarios.archive', $codes);
        $this->assertNotContains('scenarios.delete', $codes);
    }

    public function test_catalog_does_not_expose_preparatory_rows_to_user_interface(): void
    {
        $catalog = app(RolePermissionCatalog::class)->groups();

        $preparatoryCodes = collect($catalog)
            ->pluck('actions')
            ->flatten(1)
            ->filter(fn (array $action): bool => $action['isPreparatory'])
            ->pluck('code')
            ->values()
            ->all();

        $this->assertSame([], $preparatoryCodes);
    }

    public function test_catalog_marks_runtime_active_rows_explicitly(): void
    {
        $catalog = app(RolePermissionCatalog::class)->groups();

        $runtimeActiveCodes = collect($catalog)
            ->pluck('actions')
            ->flatten(1)
            ->filter(fn (array $action): bool => $action['isRuntimeActive'])
            ->pluck('code')
            ->values()
            ->all();

        $this->assertSame([
            'contacts.view',
            'contacts.edit',
            'contacts.delete',
            'dialogs.view',
            'dialogs.edit',
            'tags.view',
            'tags.edit',
            'tags.delete',
            'channels.view',
            'channels.edit',
            'auto_reply_rules.view',
            'auto_reply_rules.edit',
            'auto_reply_rules.delete',
            'bitrix24.view',
            'bitrix24.edit',
            'analytics.view',
            'analytics.debug',
            'scenarios.view',
            'scenarios.edit',
            'users.view',
            'users.edit',
        ], $runtimeActiveCodes);
    }
}
