<?php

namespace App\Filament\Resources\AutoReplyRules;

use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AutoReplyRuleResource extends Resource
{
    protected static ?string $model = AutoReplyRule::class;

    protected static ?string $recordTitleAttribute = 'keyword';

    protected static ?string $modelLabel = 'Правило автоответа';

    protected static ?string $pluralModelLabel = 'Правила автоответа';

    protected static ?string $navigationLabel = 'Правила автоответа';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Правило автоответа')
                    ->schema([
                        Select::make('channel_id')
                            ->label('Канал')
                            ->options(static::getChannelOptions())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->native(false),
                        TextInput::make('keyword')
                            ->label('Ключевое слово')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('reply_text')
                            ->label('Текст ответа')
                            ->required()
                            ->rows(6)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Select::make('telegram_button_type')
                            ->label('Кнопка')
                            ->options(AutoReplyRule::telegramButtonTypeOptions())
                            ->placeholder('Без кнопки')
                            ->native(false)
                            ->helperText('Доступно только для Telegram-каналов.')
                            ->hidden(fn (Get $get): bool => ! static::channelSupportsTelegram((int) $get('channel_id'))),
                        Toggle::make('is_active')
                            ->label('Активно')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel_display')
                    ->label('Канал')
                    ->state(fn (AutoReplyRule $record): string => static::formatChannelLabel($record->channel)),
                TextColumn::make('keyword')
                    ->label('Ключевое слово')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reply_text')
                    ->label('Текст ответа')
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (AutoReplyRule $record): string => (string) $record->reply_text),
                TextColumn::make('telegram_button_type')
                    ->label('Кнопка')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (AutoReplyRule::telegramButtonTypeOptions()[$state] ?? $state)
                        : '—'),
                TextColumn::make('is_active')
                    ->label('Активно')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(static::getChannelOptions()),
                TernaryFilter::make('is_active')
                    ->label('Статус')
                    ->placeholder('Все')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только отключённые'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Правила автоответа ещё не добавлены')
            ->emptyStateDescription('Создайте первое правило для точного совпадения текста.')
            ->recordActions([
                EditAction::make()
                    ->using(function (array $data, AutoReplyRule $record): void {
                        $record->update(static::mutateAutoReplyRuleData($data, $record));
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAutoReplyRules::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateAutoReplyRuleData(array $data, ?AutoReplyRule $record = null): array
    {
        static::guardAgainstDuplicateNormalizedKeyword($data, $record);

        $data['keyword'] = filled($data['keyword'] ?? null)
            ? trim((string) $data['keyword'])
            : $data['keyword'];
        $data['telegram_button_type'] = filled($data['telegram_button_type'] ?? null)
            ? trim((string) $data['telegram_button_type'])
            : null;
        $data['normalized_keyword'] = AutoReplyRule::normalizeKeyword($data['keyword'] ?? null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function guardAgainstDuplicateNormalizedKeyword(array $data, ?AutoReplyRule $record = null): void
    {
        $channelId = (int) ($data['channel_id'] ?? 0);
        $normalizedKeyword = AutoReplyRule::normalizeKeyword($data['keyword'] ?? null);

        if ($channelId <= 0 || ! filled($normalizedKeyword)) {
            return;
        }

        $exists = AutoReplyRule::query()
            ->where('channel_id', $channelId)
            ->where('normalized_keyword', $normalizedKeyword)
            ->when($record instanceof AutoReplyRule, fn ($query) => $query->whereKeyNot($record->id))
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'keyword' => 'Для этого канала правило с таким ключевым словом уже существует.',
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function getChannelOptions(): array
    {
        return Channel::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Channel $channel): array => [$channel->id => static::formatChannelLabel($channel)])
            ->all();
    }

    protected static function formatChannelLabel(?Channel $channel): string
    {
        if (! $channel instanceof Channel) {
            return '—';
        }

        $platform = Channel::platformOptions()[$channel->platform] ?? $channel->platform;

        return sprintf('%s (%s)', $channel->name, $platform);
    }

    protected static function channelSupportsTelegram(int $channelId): bool
    {
        if ($channelId <= 0) {
            return false;
        }

        return Channel::query()
            ->whereKey($channelId)
            ->where('platform', Channel::PLATFORM_TELEGRAM)
            ->exists();
    }
}
