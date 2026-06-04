<x-filament-panels::page>
    @php($scenarioBuilderV3Config = $this->scenarioBuilderV3Config())

    @vite('resources/js/scenario-builder-v3/main.jsx')

    <div
        data-scenario-builder-v3
        data-state-url="{{ $scenarioBuilderV3Config['stateUrl'] }}"
        data-save-url="{{ $scenarioBuilderV3Config['saveUrl'] }}"
        data-publish-url="{{ $scenarioBuilderV3Config['publishUrl'] }}"
        data-sheet-export-url="{{ $scenarioBuilderV3Config['sheetExportUrl'] }}"
        data-sheet-import-preview-url="{{ $scenarioBuilderV3Config['sheetImportPreviewUrl'] }}"
        data-sheet-import-apply-url="{{ $scenarioBuilderV3Config['sheetImportApplyUrl'] }}"
        data-auto-reply-import-preview-url="{{ $scenarioBuilderV3Config['autoReplyImportPreviewUrl'] }}"
        data-auto-reply-import-tag-store-url="{{ $scenarioBuilderV3Config['autoReplyImportTagStoreUrl'] }}"
        data-csrf-token="{{ $scenarioBuilderV3Config['csrfToken'] }}"
    >
        <section class="ac-v3-builder" data-status="loading">
            <div class="ac-v3-builder__state">Конструктор загружается...</div>
        </section>
    </div>
</x-filament-panels::page>
