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
            'dialogs.delete',
            'tags.view',
            'tags.edit',
            'tags.delete',
            'users.view',
            'users.edit',
            'users.delete',
            'channels.view',
            'channels.edit',
            'channels.delete',
            'auto_reply_rules.view',
            'auto_reply_rules.edit',
            'auto_reply_rules.delete',
            'bitrix24.view',
            'bitrix24.edit',
            'bitrix24.delete',
        ], $codes);
    }

    public function test_catalog_does_not_expose_granular_legacy_rows_or_tags(): void
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
    }

    public function test_catalog_marks_preparatory_rows_explicitly(): void
    {
        $catalog = app(RolePermissionCatalog::class)->groups();

        $preparatoryCodes = collect($catalog)
            ->pluck('actions')
            ->flatten(1)
            ->filter(fn (array $action): bool => $action['isPreparatory'])
            ->pluck('code')
            ->values()
            ->all();

        $this->assertSame([
            'dialogs.delete',
            'users.delete',
            'channels.delete',
            'bitrix24.edit',
            'bitrix24.delete',
        ], $preparatoryCodes);
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
            'users.view',
            'users.edit',
            'channels.view',
            'channels.edit',
            'auto_reply_rules.view',
            'auto_reply_rules.edit',
            'auto_reply_rules.delete',
            'bitrix24.view',
        ], $runtimeActiveCodes);
    }
}
