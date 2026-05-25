<?php

namespace App\Filament\Resources\QuestionnaireTemplates;

use App\Filament\Resources\QuestionnaireTemplates\Pages\ManageQuestionnaireTemplates;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Services\Questionnaires\PublishQuestionnaireTemplateVersionAction;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\ValidationException;
use JsonException;
use UnitEnum;

class QuestionnaireTemplateResource extends Resource
{
    protected static ?string $model = QuestionnaireTemplate::class;

    protected static ?string $recordTitleAttribute = 'name';

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
            ->with(['publishedVersion', 'draftVersion'])
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
                        ->helperText('Технический ключ, например profile.')
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
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QuestionnaireTemplate::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        QuestionnaireTemplate::STATUS_PUBLISHED => 'success',
                        QuestionnaireTemplate::STATUS_DISABLED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('published_version')
                    ->label('Опубликована')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatVersionLabel($record->publishedVersion))
                    ->badge()
                    ->color(fn (QuestionnaireTemplate $record): string => $record->publishedVersion instanceof QuestionnaireTemplateVersion ? 'success' : 'gray'),
                TextColumn::make('draft_version')
                    ->label('Черновик')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatVersionLabel($record->draftVersion))
                    ->badge()
                    ->color(fn (QuestionnaireTemplate $record): string => $record->draftVersion instanceof QuestionnaireTemplateVersion ? 'warning' : 'gray'),
                TextColumn::make('fields_summary')
                    ->label('Вопросы и настройки')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatFieldsSummary(
                        $record->draftVersion ?? $record->publishedVersion,
                    ))
                    ->wrap()
                    ->limit(220),
                TextColumn::make('fields_count')
                    ->label('Полей')
                    ->state(fn (QuestionnaireTemplate $record): int => count($record->publishedVersion?->fields_payload ?? []))
                    ->sortable(false),
                TextColumn::make('versions_count')
                    ->label('Версий')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                Action::make('editDraft')
                    ->label('Редактировать вопросы')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->tooltip('Редактировать вопросы, варианты ответов и правила шаблона')
                    ->modalHeading(fn (QuestionnaireTemplate $record): string => 'JSON анкеты: '.$record->name)
                    ->modalSubmitActionLabel('Сохранить черновик')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->fillForm(fn (QuestionnaireTemplate $record): array => [
                        'fields_payload_json' => static::encodeFieldsPayload(
                            $record->draftVersion?->fields_payload
                                ?? $record->publishedVersion?->fields_payload
                                ?? [],
                        ),
                    ])
                    ->form([
                        Textarea::make('fields_payload_json')
                            ->label('fields_payload')
                            ->helperText('JSON-массив шагов анкеты. Сохранение создаёт или обновляет черновик, но не публикует его.')
                            ->required()
                            ->rows(24)
                            ->columnSpanFull(),
                    ])
                    ->action(function (QuestionnaireTemplate $record, array $data): void {
                        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

                        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
                            $record,
                            static::decodeFieldsPayloadJson((string) ($data['fields_payload_json'] ?? '')),
                            auth()->user(),
                            'fields_payload_json',
                        );

                        Notification::make()
                            ->success()
                            ->title('Черновик анкеты сохранён')
                            ->body('Опубликованная версия не изменилась.')
                            ->send();
                    }),
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
                EditAction::make()
                    ->label('Настройки')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->tooltip('Изменить название, вопросы, варианты и правила')
                    ->modalHeading(fn (QuestionnaireTemplate $record): string => 'Настройки анкеты: '.$record->name)
                    ->modalSubmitActionLabel('Сохранить черновик')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->fillForm(fn (QuestionnaireTemplate $record): array => [
                        'key' => $record->key,
                        'name' => $record->name,
                        'fields_payload_json' => static::encodeFieldsPayload(
                            $record->draftVersion?->fields_payload
                                ?? $record->publishedVersion?->fields_payload
                                ?? [],
                        ),
                    ])
                    ->form([
                        Section::make('Общие настройки')
                            ->schema([
                                TextInput::make('key')
                                    ->label('Ключ')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('Технический ключ, например profile. После создания не меняется.'),
                                TextInput::make('name')
                                    ->label('Название')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Section::make('Вопросы, варианты ответов и правила')
                            ->description('Здесь хранится JSON шаблона анкеты: вопросы, варианты, попытки, условия и поля контакта, куда записываются ответы.')
                            ->schema([
                                Textarea::make('fields_payload_json')
                                    ->label('fields_payload')
                                    ->helperText('Сохранение обновляет черновик. Чтобы бот начал использовать изменения, нажмите «Опубликовать».')
                                    ->required()
                                    ->rows(24)
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->using(function (array $data, QuestionnaireTemplate $record): QuestionnaireTemplate {
                        $record = static::saveTemplate($data, $record);

                        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
                            $record,
                            static::decodeFieldsPayloadJson((string) ($data['fields_payload_json'] ?? '')),
                            auth()->user(),
                            'fields_payload_json',
                        );

                        return $record;
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
            'index' => ManageQuestionnaireTemplates::route('/'),
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
    public static function decodeFieldsPayloadJson(string $fieldsPayloadJson): array
    {
        $trimmedPayload = trim($fieldsPayloadJson);

        if ($trimmedPayload === '') {
            throw ValidationException::withMessages([
                'fields_payload_json' => 'Нужно указать JSON полей анкеты.',
            ]);
        }

        try {
            $decodedPayload = json_decode($trimmedPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'fields_payload_json' => 'Поля анкеты должны быть валидным JSON.',
            ]);
        }

        if (! is_array($decodedPayload) || ! array_is_list($decodedPayload)) {
            throw ValidationException::withMessages([
                'fields_payload_json' => 'Поля анкеты должны быть JSON-массивом.',
            ]);
        }

        return $decodedPayload;
    }
}
