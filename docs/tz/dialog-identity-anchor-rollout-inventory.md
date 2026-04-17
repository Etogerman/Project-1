# Приложение: grep-backed inventory для rollout `Dialog` identity-anchor

## Назначение

Это приложение фиксирует реальный inventory текущего runtime перед любыми изменениями identity-модели `Dialog`.

Документ собран не по памяти, а по grep / `rg`-поиску по `app/`, `tests/`, `database/` и точечному чтению source-of-truth файлов.

## Команды, использованные для inventory

### Текущий runtime-инвариант

```bash
sed -n '90,115p' AGENTS.md
sed -n '1,120p' database/migrations/2026_03_31_000006_create_dialogs_table.php
sed -n '1,120p' app/Services/Dialogs/ResolveOrCreateDialogAction.php
```

### Контур `current_contact_identity_id`

```bash
rg -n "current_contact_identity_id" app tests database
rg -l "current_contact_identity_id" app | sort
rg -l "current_contact_identity_id" tests | sort
```

### Route-contract

```bash
rg -n "ResolveDialogRouteStatusAction|ApplyDialogRoutePredicateAction|CanSendThroughDialogAction|ResolveDialogRouteSourceAction|ResolveDialogRoutePayloadAction" app tests
rg -l "ResolveDialogRouteStatusAction|ApplyDialogRoutePredicateAction|CanSendThroughDialogAction|ResolveDialogRouteSourceAction|ResolveDialogRoutePayloadAction" app
rg -l "ResolveDialogRouteStatusAction|ApplyDialogRoutePredicateAction|CanSendThroughDialogAction|ResolveDialogRouteSourceAction|ResolveDialogRoutePayloadAction" tests
```

### Merge / repair / backfill

```bash
rg -n "MergeContactsAction|ConsolidateDialogsForRootContactAction|RepairMergedContactDialogsCommand|BackfillDialogsCommand|BackfillDialogsForRootContactAction" app tests
rg -l "MergeContactsAction|ConsolidateDialogsForRootContactAction|RepairMergedContactDialogsCommand|BackfillDialogsCommand|BackfillDialogsForRootContactAction" app
rg -l "MergeContactsAction|ConsolidateDialogsForRootContactAction|RepairMergedContactDialogsCommand|BackfillDialogsCommand|BackfillDialogsForRootContactAction" tests
```

## Агрегированные counts

### `current_contact_identity_id`

1. hits по `app/ + tests/ + database/`: `258`
2. file count в `app/`: `18`
3. file count в `tests/`: `38`
4. file count в `database/`: `5`

### Route-contract

1. hits по `app/ + tests/`: `58`
2. file count в `app/`: `18`
3. file count в `tests/`: `4`

### Merge / repair / backfill

1. hits по `app/ + tests/`: `34`
2. file count в `app/`: `6`
3. file count в `tests/`: `5`

## Подтверждённый текущий source of truth

### `AGENTS.md`

В `AGENTS.md` зафиксирован действующий доменный инвариант:

1. `Dialog` уникален на пару `[contact_id, channel_id]`
2. `Dialog` хранит route context канала
3. `Dialog` является точкой точного manual reply route

### Схема `dialogs`

Миграция `2026_03_31_000006_create_dialogs_table.php` подтверждает:

1. наличие `current_contact_identity_id`
2. отсутствие `anchor_contact_identity_id`
3. `unique(['contact_id', 'channel_id'])`

### Runtime lookup / create

`ResolveOrCreateDialogAction` подтверждает текущий lookup-contract:

1. поиск по `contact_id`
2. поиск по `channel_id`
3. create по `contact_id + channel_id`
4. retry при unique-нарушении по тому же ключу

## Контур `current_contact_identity_id`

Это не только исторический хвост, а активный runtime-контур.

### Ключевые app-файлы

1. `app/Models/Dialog.php`
2. `app/Models/ContactIdentity.php`
3. `app/Services/Dialogs/SyncMessageDialogMetadataAction.php`
4. `app/Services/Dialogs/ResolveDialogRouteSourceAction.php`
5. `app/Services/Dialogs/ResolveDialogRoutePayloadAction.php`
6. `app/Services/Dialogs/UpdateDialogInboxStatusAction.php`
7. `app/Services/Dialogs/ConsolidateDialogsForRootContactAction.php`
8. `app/Services/Dialogs/LoadContactDialogsOverviewAction.php`
9. `app/Services/Bots/SendManualDialogReplyAction.php`
10. `app/Services/Bots/StoreManualOutboundMessageAction.php`
11. `app/Services/Bots/StoreDataCollectionOutboundMessageAction.php`
12. `app/Services/Bots/StorePhoneCaptureConfirmationAction.php`
13. `app/Services/Bitrix24/StoreBitrix24OpenLinesOutboundMessageAction.php`
14. `app/Jobs/ProcessDataCollectionResponseJob.php`
15. `app/Services/Scenarios/GenericDbScenarioRuntime.php`
16. `app/Filament/Resources/Dialogs/DialogResource.php`
17. `app/Filament/Resources/Dialogs/Pages/ViewDialog.php`
18. `app/Filament/Resources/Contacts/ContactResource.php`

### Ключевые database-файлы

1. `database/migrations/2026_03_31_000006_create_dialogs_table.php`
2. `database/migrations/2026_04_09_000001_add_pending_auto_reply_source_message_id_to_dialogs_table.php`
3. `database/migrations/2026_04_12_130100_backfill_contact_name_sources_and_identity_display_names.php`
4. `database/migrations/2026_04_12_150000_repair_dialog_identity_display_names.php`
5. `database/factories/DialogFactory.php`

### Representative tests

1. `tests/Feature/DialogSchemaTest.php`
2. `tests/Feature/DialogMetadataSyncTest.php`
3. `tests/Feature/ResolveDialogRouteStatusActionTest.php`
4. `tests/Feature/DialogRoutePredicateParityTest.php`
5. `tests/Feature/ProcessPhoneCaptureFollowUpJobTest.php`
6. `tests/Feature/ProcessDataCollectionResponseJobTest.php`
7. `tests/Feature/SendManualDialogReplyActionTest.php`
8. `tests/Feature/MergeContactsActionTest.php`
9. `tests/Feature/RepairMergedContactDialogsCommandTest.php`
10. `tests/Feature/Bitrix24OpenLinesExportJobTest.php`

## Route-contract inventory

До отдельного нового ТЗ `routable` и `sendability` должны пониматься только через уже подтверждённый runtime-contract.

### Core route-contract files

1. `app/Services/Dialogs/ResolveDialogRouteStatusAction.php`
2. `app/Services/Dialogs/ApplyDialogRoutePredicateAction.php`
3. `app/Services/Dialogs/CanSendThroughDialogAction.php`
4. `app/Services/Dialogs/ResolveDialogRouteSourceAction.php`
5. `app/Services/Dialogs/ResolveDialogRoutePayloadAction.php`

### Runtime users of route-contract

1. `app/Services/Bots/SendBotDialogTextAction.php`
2. `app/Services/Bots/SendManualDialogReplyAction.php`
3. `app/Jobs/ProcessPhoneCaptureFollowUpJob.php`
4. `app/Jobs/ProcessDataCollectionQuestionJob.php`
5. `app/Jobs/ProcessScenarioStartJob.php`
6. `app/Jobs/ProcessDeferredParameterAutoReplyJob.php`
7. `app/Services/DataCollection/ResumeContactDataCollectionAction.php`
8. `app/Filament/Resources/Dialogs/DialogResource.php`
9. `app/Filament/Resources/Dialogs/Pages/ViewDialog.php`

### Route-contract tests

1. `tests/Feature/ResolveDialogRouteStatusActionTest.php`
2. `tests/Feature/ResolveDialogRouteSourceActionTest.php`
3. `tests/Feature/DialogRoutePredicateParityTest.php`
4. `tests/Feature/MaxSuspendedDialogRuntimeTest.php`

## Merge / repair / backfill inventory

### Core files

1. `app/Services/Contacts/MergeContactsAction.php`
2. `app/Services/Dialogs/ConsolidateDialogsForRootContactAction.php`
3. `app/Services/Dialogs/BackfillDialogsForRootContactAction.php`
4. `app/Console/Commands/RepairMergedContactDialogsCommand.php`
5. `app/Console/Commands/BackfillDialogsCommand.php`
6. `app/Services/Bots/StoreInboundMessageAction.php`

### Core tests

1. `tests/Feature/MergeContactsActionTest.php`
2. `tests/Feature/RepairMergedContactDialogsCommandTest.php`
3. `tests/Feature/BackfillDialogsCommandTest.php`
4. `tests/Feature/StoreInboundMessageActionTest.php`
5. `tests/Feature/ContactHistoryIbizaScenarioTest.php`

## Что этот inventory подтверждает

1. Текущий runtime действительно живёт на инварианте `Dialog = contact_id + channel_id`.
2. `current_contact_identity_id` уже участвует в живом routing / send / display / merge / Bitrix-контуре.
3. `current_contact_identity_id` нельзя молча переобозначить в immutable anchor без отдельного transition contract.
4. Route-contract уже формализован и должен переиспользоваться, а не переизобретаться в identity-stream.
5. Merge / repair уже имеют подтверждённый runtime и test contour, поэтому будущий switch должен эволюционировать этот контур, а не игнорировать его.

## Что это приложение не делает

1. Не вводит новый runtime-contract.
2. Не меняет схему.
3. Не объявляет mixed-identity history corruption автоматически.
4. Не запускает repair.
5. Не разрешает dangerous ops.

