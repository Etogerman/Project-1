<section data-role="contact-section-questionnaires" class="ac-surface ac-surface--secondary">
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Анкеты</p>
            <h3 class="ac-surface__title">Прохождения анкет</h3>
            <p class="ac-surface__subtitle">История анкет контакта, прогресс, ответы и действия оператора.</p>
        </div>

        @if (filled($questionnaireAction['method'] ?? null))
            <button
                type="button"
                wire:click="{{ $questionnaireAction['method'] }}"
                wire:loading.attr="disabled"
                wire:target="{{ $questionnaireAction['target'] ?? $questionnaireAction['method'] }}"
                class="ac-button ac-button--primary-soft"
            >
                <span wire:loading.remove wire:target="{{ $questionnaireAction['target'] ?? $questionnaireAction['method'] }}">
                    {{ $questionnaireAction['label'] }}
                </span>
                <span wire:loading wire:target="{{ $questionnaireAction['target'] ?? $questionnaireAction['method'] }}">
                    Выполняем...
                </span>
            </button>
        @endif
    </div>

    @if (($questionnaireRunsViewData['rows'] ?? []) !== [])
        <div class="ac-bitrix-table-shell ac-contact-questionnaires-table-shell">
            <table class="ac-bitrix-table ac-contact-questionnaires-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Шаблон</th>
                        <th>Статус</th>
                        <th>Прогресс</th>
                        <th>Текущий вопрос</th>
                        <th>Ответы</th>
                        <th>Начата</th>
                        <th>Завершена</th>
                        <th>Попыток</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($questionnaireRunsViewData['rows'] as $run)
                        <tr>
                            <td>{{ $run['id'] }}</td>
                            <td>{{ $run['template'] }}</td>
                            <td>
                                <span class="ac-pill" data-tone="gray">{{ $run['status'] }}</span>
                            </td>
                            <td>{{ $run['progress'] }}</td>
                            <td>{{ $run['currentField'] }}</td>
                            <td>
                                <div class="ac-contact-questionnaire-answers">
                                    <strong>{{ $run['answersCount'] }}</strong>

                                    @if (($run['answerItems'] ?? []) !== [])
                                        <div class="ac-contact-questionnaire-answers__items">
                                            @foreach ($run['answerItems'] as $item)
                                                <p>
                                                    <span>{{ $item['label'] }}</span>
                                                    @if (filled($item['meta'] ?? null))
                                                        <small>{{ $item['meta'] }}</small>
                                                    @endif
                                                </p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $run['startedAt'] }}</td>
                            <td>{{ $run['completedAt'] }}</td>
                            <td>{{ $run['attemptsCount'] }}</td>
                            <td>
                                <div class="ac-contact-questionnaires-table__actions">
                                    @if (filled($run['cancelAction']['method'] ?? null))
                                        <button
                                            type="button"
                                            wire:click="{{ $run['cancelAction']['method'] }}"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $run['cancelAction']['target'] ?? $run['cancelAction']['method'] }}"
                                            class="ac-inline-action ac-inline-action--danger"
                                        >
                                            Отменить
                                        </button>
                                    @endif

                                    @if (filled($run['resetAction']['method'] ?? null))
                                        <button
                                            type="button"
                                            wire:click="{{ $run['resetAction']['method'] }}"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $run['resetAction']['target'] ?? $run['resetAction']['method'] }}"
                                            class="ac-inline-action"
                                        >
                                            Сбросить
                                        </button>
                                    @endif

                                    @if (! filled($run['cancelAction']['method'] ?? null) && ! filled($run['resetAction']['method'] ?? null))
                                        <span class="ac-muted">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif (($questionnaireRunsViewData['legacyRows'] ?? []) !== [])
        @include('filament.contacts.partials.contact-flat-section', [
            'dataRole' => 'contact-section-questionnaire-legacy',
            'title' => 'Старый collector',
            'rows' => $questionnaireRunsViewData['legacyRows'],
            'showFieldKeys' => $showFieldKeys,
        ])
    @else
        <div class="ac-empty-state">
            Анкет для этого контакта ещё нет.
        </div>
    @endif
</section>
