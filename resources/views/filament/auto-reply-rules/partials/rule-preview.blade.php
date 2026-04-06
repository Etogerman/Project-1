@php
    $channelLabel = $channelLabel ?? null;
    $isActive = (bool) ($isActive ?? false);
    $summaryLines = is_array($summaryLines ?? null) ? $summaryLines : [];
    $replyText = trim((string) ($replyText ?? ''));
    $buttonLabel = filled($buttonLabel ?? null) ? (string) $buttonLabel : null;
    $assignTags = is_array($assignTags ?? null) ? $assignTags : [];
    $removeTags = is_array($removeTags ?? null) ? $removeTags : [];
@endphp

<div class="space-y-4">
    <div class="rounded-2xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 shadow-sm">
        <div class="text-sm font-semibold text-gray-950">Как сработает правило</div>
        <p class="mt-1 text-xs text-gray-500">Короткая памятка по текущим условиям, чтобы не перечитывать форму сверху вниз.</p>

        <div class="mt-3 flex flex-wrap gap-2">
            @if (filled($channelLabel))
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200">
                    {{ $channelLabel }}
                </span>
            @endif

            <span @class([
                'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1',
                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $isActive,
                'bg-gray-100 text-gray-600 ring-gray-200' => ! $isActive,
            ])>
                {{ $isActive ? 'Активно' : 'Выключено' }}
            </span>
        </div>

        <ul class="mt-4 space-y-2 text-sm leading-6 text-gray-700">
            @foreach ($summaryLines as $line)
                <li class="flex items-start gap-2">
                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-blue-400"></span>
                    <span>{{ $line }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="rounded-2xl border border-amber-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-semibold text-gray-950">Ответ пользователю</div>
        <p class="mt-1 text-xs text-gray-500">Упрощённый вид того, что увидит пользователь после срабатывания правила.</p>

        <div class="mt-4 flex justify-end">
            <div class="w-full max-w-sm rounded-3xl border border-amber-200 bg-gradient-to-b from-amber-50 to-white px-4 py-3 shadow-sm">
                <div class="whitespace-pre-line text-sm leading-6 text-gray-900">
                    {{ $replyText !== '' ? $replyText : 'Текст ответа пока не заполнен.' }}
                </div>

                @if ($buttonLabel !== null)
                    <div class="mt-3 inline-flex items-center rounded-full bg-white px-3 py-1.5 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
                        {{ $buttonLabel }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-semibold text-gray-950">Дополнительные действия</div>
        <p class="mt-1 text-xs text-gray-500">Эффекты применяются только после успешной отправки автоответа.</p>

        @if ($assignTags === [] && $removeTags === [])
            <div class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-600">
                Теги не изменятся.
            </div>
        @else
            <div class="mt-4 space-y-4">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Назначить</div>

                    @if ($assignTags === [])
                        <div class="mt-2 text-sm text-gray-500">Новых тегов нет.</div>
                    @else
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($assignTags as $tagLabel)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">
                                    + {{ $tagLabel }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Снять</div>

                    @if ($removeTags === [])
                        <div class="mt-2 text-sm text-gray-500">Снимаемых тегов нет.</div>
                    @else
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($removeTags as $tagLabel)
                                <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-200">
                                    - {{ $tagLabel }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
