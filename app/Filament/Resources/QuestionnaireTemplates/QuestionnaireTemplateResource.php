<?php

namespace App\Filament\Resources\QuestionnaireTemplates;

use App\Filament\Resources\QuestionnaireTemplates\Pages\CreateQuestionnaireTemplate;
use App\Filament\Resources\QuestionnaireTemplates\Pages\EditQuestionnaireTemplate;
use App\Filament\Resources\QuestionnaireTemplates\Pages\ListQuestionnaireTemplates;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Services\Questionnaires\PublishQuestionnaireTemplateVersionAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class QuestionnaireTemplateResource extends Resource
{
    protected static ?string $model = QuestionnaireTemplate::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $recordRouteKeyName = 'key';

    protected static ?string $modelLabel = 'Анкета';

    protected static ?string $pluralModelLabel = 'Анкеты';

    protected static ?string $navigationLabel = 'Анкеты';

    protected static string|UnitEnum|null $navigationGroup = 'Автоматизация';

    protected static ?int $navigationSort = 17;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('questionnaire_templates')
            && auth()->user()?->canManageSystem() === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['publishedVersion', 'draftVersion', 'updater'])
            ->withCount(['versions', 'runs']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Анкета')
                ->description('Здесь задаются ключ и название. Вопросы, варианты ответов и правила редактируются в настройках существующей анкеты.')
                ->schema([
                    TextInput::make('key')
                        ->label('Ключ')
                        ->required()
                        ->maxLength(80)
                        ->regex('/^[a-z0-9_]+$/')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?QuestionnaireTemplate $record): bool => $record instanceof QuestionnaireTemplate)
                        ->dehydrated(fn (?QuestionnaireTemplate $record): bool => ! $record instanceof QuestionnaireTemplate),
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QuestionnaireTemplate::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        QuestionnaireTemplate::STATUS_PUBLISHED => 'success',
                        QuestionnaireTemplate::STATUS_DISABLED => 'gray',
                        default => 'warning',
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('published_version')
                    ->label('Опубликована')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatVersionLabel($record->publishedVersion))
                    ->badge()
                    ->color(fn (QuestionnaireTemplate $record): string => $record->publishedVersion instanceof QuestionnaireTemplateVersion ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('draft_version')
                    ->label('Черновик')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatVersionLabel($record->draftVersion))
                    ->badge()
                    ->color(fn (QuestionnaireTemplate $record): string => $record->draftVersion instanceof QuestionnaireTemplateVersion ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('fields_summary')
                    ->label('Вопросы и настройки')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatFieldsSummary(
                        $record->draftVersion ?? $record->publishedVersion,
                    ))
                    ->wrap()
                    ->limit(220)
                    ->toggleable(),
                TextColumn::make('fields_count')
                    ->label('Полей')
                    ->state(fn (QuestionnaireTemplate $record): int => count($record->publishedVersion?->fields_payload ?? []))
                    ->sortable(false)
                    ->toggleable(),
                TextColumn::make('versions_count')
                    ->label('Версий')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                EditAction::make()
                    ->label('Редактировать')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip('Открыть полноэкранный редактор анкеты')
                    ->url(fn (QuestionnaireTemplate $record): string => static::getUrl('edit', ['record' => $record])),
                Action::make('publishDraft')
                    ->label('Опубликовать')
                    ->icon(Heroicon::OutlinedBolt)
                    ->tooltip('Опубликовать черновик')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (QuestionnaireTemplate $record): bool => $record->draftVersion instanceof QuestionnaireTemplateVersion
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (QuestionnaireTemplate $record): void {
                        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

                        $draftVersion = $record->draftVersion()->first();

                        if (! $draftVersion instanceof QuestionnaireTemplateVersion) {
                            throw ValidationException::withMessages([
                                'version' => 'У анкеты нет черновика для публикации.',
                            ]);
                        }

                        app(PublishQuestionnaireTemplateVersionAction::class)->handle($draftVersion, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Черновик анкеты опубликован')
                            ->body('Runtime будет использовать новую опубликованную версию.')
                            ->send();
                    }),
                DeleteAction::make()
                    ->label('Удалить')
                    ->icon(Heroicon::OutlinedTrash)
                    ->tooltip('Удалить')
                    ->hidden(fn (QuestionnaireTemplate $record): bool => $record->runs_count > 0 || $record->versions_count > 0),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuestionnaireTemplates::route('/'),
            'create' => CreateQuestionnaireTemplate::route('/create'),
            'edit' => EditQuestionnaireTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveTemplate(array $data, QuestionnaireTemplate $record): QuestionnaireTemplate
    {
        $record->fill([
            'name' => (string) ($data['name'] ?? $record->name),
            'updated_by' => auth()->id(),
        ])->save();

        return $record;
    }

    /**
     * @return list<mixed>
     */
    public static function editorFormSchema(?array $defaultFieldsPayload = null, bool $includeIdentitySection = true): array
    {
        $schema = [];

        if ($includeIdentitySection) {
            $schema[] = Section::make('Анкета')
                ->schema([
                    TextInput::make('key')
                        ->label('Ключ')
                        ->required()
                        ->maxLength(80)
                        ->regex('/^[a-z0-9_]+$/')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?QuestionnaireTemplate $record): bool => $record instanceof QuestionnaireTemplate)
                        ->dehydrated(fn (?QuestionnaireTemplate $record): bool => ! $record instanceof QuestionnaireTemplate),
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(2)
                ->compact()
                ->extraAttributes(['class' => 'qe-sect qe-sect--identity'])
                ->columnSpanFull();
        }

        return [
            ...$schema,
            ...static::fieldsPayloadFormSchema($defaultFieldsPayload),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function fieldsPayloadFormSchema(?array $defaultFieldsPayload = null): array
    {
        $defaultData = static::fieldsPayloadEditorFormData($defaultFieldsPayload ?? []);

        return [
            Section::make('Поля анкеты')
                ->description('Технология задаёт проверку ответа. Для имени: Справочник + ИИ. Куда записывать задаёт поле контакта.')
                ->collapsible()
                ->schema([
                    Repeater::make('fields_table')
                        ->label('Поля')
                        ->hiddenLabel()
                        ->compact()
                        ->reorderableWithDragAndDrop()
                        ->extraAttributes(['class' => 'qe-tbl qe-tbl--fields'])
                        ->table([
                            TableColumn::make('#')->width('32px'),
                            TableColumn::make('Ключ')->width('140px')->markAsRequired(),
                            TableColumn::make('Название')->markAsRequired(),
                            TableColumn::make('Технология')->width('150px')->markAsRequired(),
                            TableColumn::make('Куда записывать'),
                            TableColumn::make('Обяз.')->width('64px'),
                            TableColumn::make('Пропуск')->width('64px'),
                            TableColumn::make('Поп.')->width('48px')->markAsRequired(),
                            TableColumn::make('Когда спрашивать')->width('190px'),
                            TableColumn::make('Словарь')->width('116px'),
                        ])
                        ->schema([
                            TextInput::make('order')
                                ->label('Порядок')
                                ->hiddenLabel()
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('field_key')
                                ->label('Ключ')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(80)
                                ->regex('/^[A-Za-z][A-Za-z0-9_]*$/')
                                ->extraInputAttributes(['class' => 'qe-mono']),
                            TextInput::make('label')
                                ->label('Название')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(120),
                            Select::make('type')
                                ->label('Технология')
                                ->hiddenLabel()
                                ->options(static::fieldTypeOptions())
                                ->allowHtml()
                                ->required()
                                ->native(false)
                                ->live()
                                ->selectablePlaceholder(false),
                            Select::make('target')
                                ->label('Куда записывать')
                                ->hiddenLabel()
                                ->options(static::targetOptions())
                                ->placeholder('-')
                                ->native(false)
                                ->extraInputAttributes(['class' => 'qe-mono']),
                            Checkbox::make('required')
                                ->label('Обязательное')
                                ->hiddenLabel(),
                            Checkbox::make('allow_skip')
                                ->label('Можно пропустить')
                                ->hiddenLabel(),
                            Hidden::make('overwrite_contact'),
                            TextInput::make('max_attempts')
                                ->label('Попыток')
                                ->hiddenLabel()
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(10)
                                ->required(),
                            Textarea::make('required_when')
                                ->label('Когда спрашивать')
                                ->hiddenLabel()
                                ->placeholder('- всегда -')
                                ->rows(3)
                                ->extraInputAttributes(['class' => 'qe-mono']),
                            Select::make('dictionary_key')
                                ->label('Словарь')
                                ->hiddenLabel()
                                ->options([
                                    'names' => 'Имена',
                                ])
                                ->default('names')
                                ->native(false)
                                ->selectablePlaceholder(false)
                                ->visible(fn (Get $get): bool => $get('type') === 'dictionary'),
                        ])
                        ->addActionLabel('+ Добавить поле')
                        ->default($defaultData['fields_table'])
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->compact()
                ->extraAttributes(['class' => 'qe-sect qe-sect--fields'])
                ->columnSpanFull(),
            Section::make('Тексты вопросов')
                ->description('Разные формулировки для каждой попытки. Если текстов меньше «Попыток» - последний повторяется.')
                ->collapsible()
                ->schema([
                    Repeater::make('prompts_table')
                        ->label('Тексты')
                        ->hiddenLabel()
                        ->compact()
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'qe-tbl qe-tbl--prompts'])
                        ->table([
                            TableColumn::make('Ключ поля')->width('140px')->markAsRequired(),
                            TableColumn::make('Попытка')->width('80px')->markAsRequired(),
                            TableColumn::make('Текст вопроса')->markAsRequired(),
                        ])
                        ->schema([
                            TextInput::make('field_key')
                                ->label('Ключ поля')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(80)
                                ->extraInputAttributes(['class' => 'qe-mono']),
                            TextInput::make('attempt')
                                ->label('Попытка')
                                ->hiddenLabel()
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(10)
                                ->required(),
                            TextInput::make('text')
                                ->label('Текст вопроса')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(1000),
                        ])
                        ->addActionLabel('+ Добавить текст')
                        ->default($defaultData['prompts_table'])
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->compact()
                ->extraAttributes(['class' => 'qe-sect qe-sect--prompts'])
                ->columnSpanFull(),
            Section::make('Варианты ответов')
                ->description('Для полей типа «Выбор» - общая таблица вариантов.')
                ->collapsible()
                ->schema([
                    Repeater::make('options_table')
                        ->label('Варианты')
                        ->hiddenLabel()
                        ->compact()
                        ->extraAttributes(['class' => 'qe-tbl qe-tbl--options'])
                        ->table([
                            TableColumn::make('Ключ поля')->width('140px')->markAsRequired(),
                            TableColumn::make('Значение')->width('160px')->markAsRequired(),
                            TableColumn::make('Подпись для клиента')->markAsRequired(),
                        ])
                        ->schema([
                            TextInput::make('field_key')
                                ->label('Ключ поля')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(80)
                                ->extraInputAttributes(['class' => 'qe-mono']),
                            TextInput::make('value')
                                ->label('Значение')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(120)
                                ->extraInputAttributes(['class' => 'qe-mono']),
                            TextInput::make('label')
                                ->label('Подпись для клиента')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(120),
                        ])
                        ->addActionLabel('+ Добавить вариант')
                        ->default($defaultData['options_table'])
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->compact()
                ->extraAttributes(['class' => 'qe-sect qe-sect--options'])
                ->columnSpanFull(),
            Section::make('JSON')
                ->description('Полный контракт анкеты. Собирается из таблиц выше при сохранении черновика.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Placeholder::make('fields_payload_preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => static::buildFieldsPayloadPreview($get))
                        ->columnSpanFull(),
                ])
                ->compact()
                ->extraAttributes(['class' => 'qe-sect qe-json'])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultFieldsPayload(): array
    {
        return [
            [
                'order' => 1,
                'field_key' => 'question',
                'label' => 'Вопрос',
                'type' => 'text',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'prompts' => [
                    'Напиши ответ',
                    'Уточни, пожалуйста, ответ',
                    'Ответь одним сообщением, чтобы мы продолжили',
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function fieldTypeOptions(): array
    {
        return [
            'text' => static::fieldTypeChip('text', 'Текст'),
            'choice' => static::fieldTypeChip('choice', 'Выбор'),
            'dictionary' => static::fieldTypeChip('dictionary', 'Справочник + ИИ'),
            'phone' => static::fieldTypeChip('phone', 'Телефон'),
        ];
    }

    protected static function fieldTypeChip(string $type, string $label): string
    {
        $description = static::fieldTypeDescription($type);

        return sprintf(
            '<span class="qe-typechip t-%s" title="%s" aria-label="%s: %s" tabindex="0"><span class="qe-typechip__dot"></span>%s<span class="qe-typechip__help" aria-hidden="true">?</span></span>',
            e($type),
            e($description),
            e($label),
            e($description),
            e($label),
        );
    }

    protected static function fieldTypeDescription(string $type): string
    {
        return match ($type) {
            'text' => 'Свободный текст. Проверяется только что ответ не пустой, потом значение пишется в выбранное поле контакта.',
            'choice' => 'Выбор из вариантов. Ответ должен совпасть с кнопкой, значением или названием варианта.',
            'dictionary' => 'Для имени: сначала словарь names. Если словарь не нашёл ответ - ИИ проверяет, что это имя. Потом результат нормализуется и пишется в контакт.',
            'phone' => 'Телефон. Ответ нормализуется в номер телефона и записывается в контакты клиента.',
            default => 'Технология определяет, как проверяется ответ клиента и как нормализуется значение перед записью.',
        };
    }

    /**
     * @return array<string, string>
     */
    protected static function targetOptions(): array
    {
        return [
            'contact.first_name' => 'contact.first_name - Имя',
            'contact.gender' => 'contact.gender - Пол',
            'contact.country' => 'contact.country - Страна',
            'contact.city' => 'contact.city - Город',
            'contact.region' => 'contact.region - Регион',
            'contact.age_years' => 'contact.age_years - Возраст (число лет)',
            'contact.age_range' => 'contact.age_range - Возрастной диапазон',
            'contact.phone' => 'contact.phone - Телефон',
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected static function formatFieldEditorItemLabel(array $state): ?string
    {
        $label = trim((string) ($state['label'] ?? ''));
        $fieldKey = trim((string) ($state['field_key'] ?? ''));

        if ($label === '' && $fieldKey === '') {
            return null;
        }

        if ($label === '') {
            return $fieldKey;
        }

        return $fieldKey === '' ? $label : "{$label} · {$fieldKey}";
    }

    /**
     * @param  list<array<string, mixed>>  $fieldsPayload
     * @return array{fields_table:list<array<string,mixed>>,prompts_table:list<array<string,mixed>>,options_table:list<array<string,mixed>>}
     */
    public static function fieldsPayloadEditorFormData(array $fieldsPayload): array
    {
        $fieldsTable = [];
        $promptsTable = [];
        $optionsTable = [];

        foreach (array_values($fieldsPayload) as $fieldIndex => $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldKey = trim((string) ($field['field_key'] ?? ''));
            $type = trim((string) ($field['type'] ?? ''));

            $fieldsTable[] = [
                'order' => $fieldIndex + 1,
                'field_key' => $fieldKey,
                'label' => trim((string) ($field['label'] ?? '')),
                'type' => $type,
                'target' => trim((string) ($field['target'] ?? '')),
                'required' => static::booleanFormValue($field['required'] ?? false),
                'allow_skip' => static::booleanFormValue($field['allow_skip'] ?? false),
                'overwrite_contact' => static::booleanFormValue($field['overwrite_contact'] ?? false),
                'max_attempts' => (int) ($field['max_attempts'] ?? 1),
                'required_when' => trim((string) ($field['required_when'] ?? '')),
                'dictionary_key' => $type === 'dictionary'
                    ? trim((string) ($field['dictionary_key'] ?? 'names'))
                    : '',
            ];

            foreach (static::normalizeStringList($field['prompts'] ?? []) as $attemptIndex => $prompt) {
                $promptsTable[] = [
                    'field_key' => $fieldKey,
                    'attempt' => $attemptIndex + 1,
                    'text' => $prompt,
                ];
            }

            foreach (static::normalizeOptionsList($field['options'] ?? []) as $option) {
                $optionsTable[] = [
                    'field_key' => $fieldKey,
                    'value' => $option['value'],
                    'label' => $option['label'],
                ];
            }
        }

        return [
            'fields_table' => $fieldsTable,
            'prompts_table' => $promptsTable,
            'options_table' => $optionsTable,
        ];
    }

    protected static function formatVersionLabel(?QuestionnaireTemplateVersion $version): string
    {
        return $version instanceof QuestionnaireTemplateVersion ? 'v'.$version->version : 'Нет';
    }

    protected static function formatFieldsSummary(?QuestionnaireTemplateVersion $version): string
    {
        $fields = $version?->fields_payload ?? [];

        if (! is_array($fields) || $fields === []) {
            return 'Поля не настроены';
        }

        return collect($fields)
            ->take(6)
            ->map(function (mixed $field): string {
                if (! is_array($field)) {
                    return 'Некорректное поле';
                }

                $label = trim((string) ($field['label'] ?? $field['field_key'] ?? 'Поле'));
                $type = trim((string) ($field['type'] ?? ''));
                $target = trim((string) ($field['target'] ?? ''));
                $optionsCount = is_array($field['options'] ?? null) ? count($field['options']) : 0;
                $parts = [$label];

                if ($type !== '') {
                    $parts[] = $type;
                }

                if ($target !== '') {
                    $parts[] = $target;
                }

                if ($optionsCount > 0) {
                    $suffix = match (true) {
                        $optionsCount === 1 => '',
                        $optionsCount >= 2 && $optionsCount <= 4 => 'а',
                        default => 'ов',
                    };

                    $parts[] = $optionsCount.' вариант'.$suffix;
                }

                return implode(' · ', $parts);
            })
            ->join('; ');
    }

    public static function buildEditorOverview(?QuestionnaireTemplate $record): HtmlString
    {
        $activeVersion = $record?->draftVersion ?? $record?->publishedVersion;
        $publishedVersion = static::formatVersionLabel($record?->publishedVersion);
        $fieldsCount = count($activeVersion?->fields_payload ?? []);
        $updatedAt = $record?->updated_at?->format('d.m.Y H:i') ?? '-';
        $updater = $record?->updater?->name ?: ($record?->updater?->email ?: '-');
        $status = $record?->status ?? QuestionnaireTemplate::STATUS_DRAFT;
        $statusLabel = QuestionnaireTemplate::statusOptions()[$status] ?? $status;
        $draftBadge = $record?->draftVersion instanceof QuestionnaireTemplateVersion
            && $record->status !== QuestionnaireTemplate::STATUS_DRAFT
            ? '<span class="qe-status s-draft">Черновик</span>'
            : '';

        return new HtmlString(sprintf(
            '<div class="qe-editor-overview">'
            .'<div class="qe-editor-overview__main">'
            .'<div class="qe-editor-overview__title-row"><span class="qe-tech">%s</span><span class="qe-status s-%s">%s</span>%s<span class="qe-ver">ver %s</span></div>'
            .'<div class="qe-editor-overview__meta"><span>Поля: <b>%d</b></span><span>Изменено %s</span><span>%s</span></div>'
            .'</div>'
            .'</div>',
            e($record?->key ?? 'new'),
            e($status),
            e($statusLabel),
            $draftBadge,
            e($publishedVersion),
            $fieldsCount,
            e($updatedAt),
            e($updater),
        ));
    }

    protected static function buildFieldsPayloadPreview(Get $get): HtmlString
    {
        $payload = static::normalizeTableEditorPayload([
            'fields_table' => is_array($get('fields_table')) ? $get('fields_table') : [],
            'prompts_table' => is_array($get('prompts_table')) ? $get('prompts_table') : [],
            'options_table' => is_array($get('options_table')) ? $get('options_table') : [],
        ]);

        return new HtmlString(
            '<div class="qe-json-preview">'
            .'<span>read-only</span>'
            .'<pre>'.e(static::encodeFieldsPayload($payload)).'</pre>'
            .'<p>Поля редактируются в таблицах выше. JSON собирается автоматически.</p>'
            .'</div>'
        );
    }

    /**
     * @param  array<mixed, mixed>  $fieldsPayload
     */
    public static function encodeFieldsPayload(array $fieldsPayload): string
    {
        if ($fieldsPayload === []) {
            return '[]';
        }

        $encoded = json_encode(
            $fieldsPayload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalizeStructuredFieldsPayload(mixed $fieldsPayload): array
    {
        if (! is_array($fieldsPayload)) {
            throw ValidationException::withMessages([
                'fields_payload' => 'Поля анкеты должны быть списком.',
            ]);
        }

        return collect($fieldsPayload)
            ->values()
            ->map(function (mixed $field): array {
                if (! is_array($field)) {
                    return [
                        'field_key' => '',
                        'label' => '',
                        'type' => '',
                        'required' => false,
                        'allow_skip' => false,
                        'max_attempts' => 1,
                        'prompts' => [],
                    ];
                }

                $type = trim((string) ($field['type'] ?? ''));
                $target = trim((string) ($field['target'] ?? ''));
                $requiredWhen = trim((string) ($field['required_when'] ?? ''));
                $dictionaryKey = trim((string) ($field['dictionary_key'] ?? ''));

                $normalized = [
                    'field_key' => trim((string) ($field['field_key'] ?? '')),
                    'label' => trim((string) ($field['label'] ?? '')),
                    'type' => $type,
                    'required' => static::booleanFormValue($field['required'] ?? false),
                    'allow_skip' => static::booleanFormValue($field['allow_skip'] ?? false),
                    'max_attempts' => max(1, min(10, (int) ($field['max_attempts'] ?? 1))),
                    'prompts' => static::normalizeStringList($field['prompts'] ?? []),
                ];

                if ($target !== '') {
                    $normalized['target'] = $target;
                }

                if (array_key_exists('overwrite_contact', $field)) {
                    $normalized['overwrite_contact'] = static::booleanFormValue($field['overwrite_contact']);
                }

                if ($requiredWhen !== '') {
                    $normalized['required_when'] = $requiredWhen;
                }

                if ($type === 'dictionary') {
                    $normalized['dictionary_key'] = $dictionaryKey !== '' ? $dictionaryKey : 'names';
                }

                if ($type === 'choice') {
                    $normalized['options'] = static::normalizeOptionsList($field['options'] ?? []);
                }

                return $normalized;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function normalizeTableEditorPayload(array $data): array
    {
        $fieldsTable = is_array($data['fields_table'] ?? null) ? array_values($data['fields_table']) : [];
        $promptsTable = is_array($data['prompts_table'] ?? null) ? array_values($data['prompts_table']) : [];
        $optionsTable = is_array($data['options_table'] ?? null) ? array_values($data['options_table']) : [];

        $promptsByField = [];

        foreach ($promptsTable as $rowIndex => $promptRow) {
            if (! is_array($promptRow)) {
                continue;
            }

            $fieldKey = trim((string) ($promptRow['field_key'] ?? ''));

            if ($fieldKey === '') {
                continue;
            }

            $text = trim((string) ($promptRow['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $promptsByField[$fieldKey][] = [
                'attempt' => (int) ($promptRow['attempt'] ?? ($rowIndex + 1)),
                'text' => $text,
                'row' => $rowIndex,
            ];
        }

        foreach ($promptsByField as $fieldKey => $prompts) {
            usort($prompts, static fn (array $a, array $b): int => [$a['attempt'], $a['row']] <=> [$b['attempt'], $b['row']]);

            $promptsByField[$fieldKey] = array_map(
                static fn (array $prompt): string => $prompt['text'],
                $prompts,
            );
        }

        $optionsByField = [];

        foreach ($optionsTable as $optionRow) {
            if (! is_array($optionRow)) {
                continue;
            }

            $fieldKey = trim((string) ($optionRow['field_key'] ?? ''));

            if ($fieldKey === '') {
                continue;
            }

            $optionsByField[$fieldKey][] = [
                'value' => trim((string) ($optionRow['value'] ?? '')),
                'label' => trim((string) ($optionRow['label'] ?? '')),
            ];
        }

        return collect($fieldsTable)
            ->values()
            ->map(function (mixed $field) use ($promptsByField, $optionsByField): array {
                if (! is_array($field)) {
                    return [
                        'field_key' => '',
                        'label' => '',
                        'type' => '',
                        'required' => false,
                        'allow_skip' => false,
                        'max_attempts' => 0,
                        'prompts' => [],
                    ];
                }

                $type = trim((string) ($field['type'] ?? ''));
                $fieldKey = trim((string) ($field['field_key'] ?? ''));
                $target = trim((string) ($field['target'] ?? ''));
                $requiredWhen = trim((string) ($field['required_when'] ?? ''));
                $dictionaryKey = trim((string) ($field['dictionary_key'] ?? ''));

                $normalized = [
                    'field_key' => $fieldKey,
                    'label' => trim((string) ($field['label'] ?? '')),
                    'type' => $type,
                    'required' => static::booleanFormValue($field['required'] ?? false),
                    'allow_skip' => static::booleanFormValue($field['allow_skip'] ?? false),
                    'max_attempts' => (int) ($field['max_attempts'] ?? 0),
                    'prompts' => $promptsByField[$fieldKey] ?? [],
                ];

                if ($target !== '') {
                    $normalized['target'] = $target;
                }

                if (array_key_exists('overwrite_contact', $field)) {
                    $normalized['overwrite_contact'] = static::booleanFormValue($field['overwrite_contact']);
                }

                if ($requiredWhen !== '') {
                    $normalized['required_when'] = $requiredWhen;
                }

                if ($type === 'dictionary') {
                    $normalized['dictionary_key'] = $dictionaryKey !== '' ? $dictionaryKey : 'names';
                }

                if ($type === 'choice') {
                    $normalized['options'] = $optionsByField[$fieldKey] ?? [];
                }

                return $normalized;
            })
            ->all();
    }

    private static function booleanFormValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->values()
            ->map(function (mixed $value): string {
                if (is_array($value)) {
                    $value = $value['text'] ?? $value['prompt'] ?? reset($value);
                }

                return is_scalar($value) ? trim((string) $value) : '';
            })
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private static function normalizeOptionsList(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->values()
            ->map(function (mixed $option): array {
                if (! is_array($option)) {
                    return [
                        'value' => '',
                        'label' => '',
                    ];
                }

                return [
                    'value' => trim((string) ($option['value'] ?? '')),
                    'label' => trim((string) ($option['label'] ?? '')),
                ];
            })
            ->all();
    }
}
