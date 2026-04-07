@php
    /** @var \App\Data\AutoReplyRules\AutoReplyRuleWorkbookPreviewData|null $preview */
    $createRows = $preview?->createRows ?? [];
    $updateRows = $preview?->updateRows ?? [];
    $errors = $preview?->errors ?? [];
@endphp

<div class="space-y-4 text-sm text-gray-700">
    <div class="grid gap-3 md:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Создать</div>
            <div class="mt-1 text-lg font-semibold text-gray-900">{{ $preview?->createCount() ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Обновить</div>
            <div class="mt-1 text-lg font-semibold text-gray-900">{{ $preview?->updateCount() ?? 0 }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Ошибки</div>
            <div class="mt-1 text-lg font-semibold {{ ($preview?->hasErrors() ?? false) ? 'text-danger-600' : 'text-success-600' }}">
                {{ $preview?->errorCount() ?? 0 }}
            </div>
        </div>
    </div>

    @if ($createRows !== [])
        <div class="rounded-xl border border-gray-200 px-4 py-3">
            <div class="font-medium text-gray-900">Строки на создание</div>
            <div class="mt-1 text-gray-600">
                {{ implode(', ', array_map(fn (\App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowData $row): string => (string) $row->rowNumber, $createRows)) }}
            </div>
        </div>
    @endif

    @if ($updateRows !== [])
        <div class="rounded-xl border border-gray-200 px-4 py-3">
            <div class="font-medium text-gray-900">Строки на обновление</div>
            <div class="mt-1 text-gray-600">
                {{ implode(', ', array_map(fn (\App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowData $row): string => (string) $row->rowNumber, $updateRows)) }}
            </div>
        </div>
    @endif

    @if ($errors !== [])
        <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3">
            <div class="font-medium text-danger-700">Ошибки импорта</div>
            <ul class="mt-2 space-y-2 text-danger-700">
                @foreach ($errors as $error)
                    <li>
                        <span class="font-medium">Строка {{ $error->rowNumber }}</span>
                        <span class="text-danger-600">· {{ $error->column }}</span>
                        <span>· {{ $error->message }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-success-700">
            Ошибок не найдено. Предпросмотр готов к применению.
        </div>
    @endif
</div>
