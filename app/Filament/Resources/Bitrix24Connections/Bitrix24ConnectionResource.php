<?php

namespace App\Filament\Resources\Bitrix24Connections;

use App\Filament\Resources\Bitrix24Connections\Pages\ListBitrix24Connections;
use App\Filament\Resources\Bitrix24Connections\Pages\ViewBitrix24Connection;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class Bitrix24ConnectionResource extends Resource
{
    protected static ?string $model = Bitrix24Connection::class;

    protected static ?string $modelLabel = 'Подключение Bitrix24';

    protected static ?string $pluralModelLabel = 'Подключения Bitrix24';

    protected static ?string $navigationLabel = 'Bitrix24';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Bitrix24Connection) {
            return parent::getRecordTitle($record);
        }

        return sprintf('#%d %s', $record->id, $record->portal_domain);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Подключение')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        TextEntry::make('portal_domain')
                            ->label('Портал')
                            ->placeholder('—'),
                        TextEntry::make('application_name')
                            ->label('Приложение')
                            ->placeholder('—'),
                        TextEntry::make('client_id')
                            ->label('Client ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('member_id')
                            ->label('Member ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::formatConnectionStatus($state))
                            ->color(fn (?string $state): string => static::getConnectionStatusColor($state)),
                        TextEntry::make('installed_at')
                            ->label('Установлено')
                            ->placeholder('—')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('access_token_expires_at')
                            ->label('Токен истекает')
                            ->placeholder('—')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('last_refreshed_at')
                            ->label('Последний refresh')
                            ->placeholder('—')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('last_install_callback_at')
                            ->label('Последний install callback')
                            ->placeholder('—')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('last_events_callback_at')
                            ->label('Последний events callback')
                            ->placeholder('—')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('last_openlines_callback_at')
                            ->label('Последний openlines callback')
                            ->placeholder('—')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('last_error_at')
                            ->label('Последняя ошибка')
                            ->placeholder('Ошибок не было')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('last_error_message')
                            ->label('Текст ошибки')
                            ->state(fn (Bitrix24Connection $record): string => filled($record->last_error_message) ? (string) $record->last_error_message : 'Ошибок не было')
                            ->columnSpanFull()
                            ->wrap(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('Последние callback-и')
                    ->schema([
                        ViewEntry::make('webhook_events')
                            ->hiddenLabel()
                            ->view('filament.bitrix24-connections.partials.webhook-events')
                            ->viewData(fn (): array => [
                                'callbackTypeOptions' => static::getWebhookEventCallbackTypeOptions(),
                                'processingStatusOptions' => static::getWebhookEventProcessingStatusOptions(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Последние sync-логи')
                    ->schema([
                        ViewEntry::make('sync_logs')
                            ->hiddenLabel()
                            ->view('filament.bitrix24-connections.partials.sync-logs')
                            ->viewData(fn (): array => [
                                'statusOptions' => static::getSyncLogStatusOptions(),
                            ])
                            ->columnSpanFull(),
                    ])
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
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('portal_domain')
                    ->label('Портал')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::formatConnectionStatus($state))
                    ->color(fn (?string $state): string => static::getConnectionStatusColor($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('installed_at')
                    ->label('Установлено')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_refreshed_at')
                    ->label('Последний refresh')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_install_callback_at')
                    ->label('Install')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_events_callback_at')
                    ->label('Events')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_openlines_callback_at')
                    ->label('Open Lines')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error_at')
                    ->label('Последняя ошибка')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error_message')
                    ->label('Текст ошибки')
                    ->state(fn (Bitrix24Connection $record): string => filled($record->last_error_message) ? (string) $record->last_error_message : '—')
                    ->limit(60)
                    ->tooltip(fn (Bitrix24Connection $record): ?string => filled($record->last_error_message) ? (string) $record->last_error_message : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(static::getConnectionStatusOptions()),
                TernaryFilter::make('has_error')
                    ->label('Ошибка')
                    ->placeholder('Все')
                    ->trueLabel('Только с ошибкой')
                    ->falseLabel('Только без ошибки')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('last_error_at'),
                        false: fn ($query) => $query->whereNull('last_error_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(\Filament\Support\Enums\Width::Medium)
            ->columnManagerTriggerAction(
                fn (\Filament\Actions\Action $action): \Filament\Actions\Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->recordUrl(fn (Bitrix24Connection $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Подключения Bitrix24 ещё не появились')
            ->emptyStateDescription('Экран диагностики станет полезен после первого install callback.')
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBitrix24Connections::route('/'),
            'view' => ViewBitrix24Connection::route('/{record}'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getConnectionStatusOptions(): array
    {
        return [
            Bitrix24Connection::STATUS_ACTIVE => 'Активно',
            Bitrix24Connection::STATUS_INVALID => 'Невалидно',
            Bitrix24Connection::STATUS_NEEDS_REINSTALL => 'Требуется переустановка',
        ];
    }

    public static function formatConnectionStatus(?string $status): string
    {
        return static::getConnectionStatusOptions()[$status] ?? ($status ?: '—');
    }

    public static function getConnectionStatusColor(?string $status): string
    {
        return match ($status) {
            Bitrix24Connection::STATUS_ACTIVE => 'success',
            Bitrix24Connection::STATUS_NEEDS_REINSTALL => 'warning',
            Bitrix24Connection::STATUS_INVALID => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function getWebhookEventCallbackTypeOptions(): array
    {
        return [
            Bitrix24WebhookEvent::TYPE_INSTALL => 'Install',
            Bitrix24WebhookEvent::TYPE_EVENTS => 'Events',
            Bitrix24WebhookEvent::TYPE_OPENLINES => 'Open Lines',
        ];
    }

    public static function formatWebhookEventCallbackType(?string $type): string
    {
        return static::getWebhookEventCallbackTypeOptions()[$type] ?? ($type ?: '—');
    }

    /**
     * @return array<string, string>
     */
    public static function getWebhookEventProcessingStatusOptions(): array
    {
        return [
            Bitrix24WebhookEvent::STATUS_PENDING => 'В очереди',
            Bitrix24WebhookEvent::STATUS_PROCESSED => 'Обработан',
            Bitrix24WebhookEvent::STATUS_FAILED => 'Ошибка',
            Bitrix24WebhookEvent::STATUS_IGNORED => 'Игнорирован',
        ];
    }

    public static function formatWebhookEventProcessingStatus(?string $status): string
    {
        return static::getWebhookEventProcessingStatusOptions()[$status] ?? ($status ?: '—');
    }

    public static function getWebhookEventProcessingStatusTone(?string $status): string
    {
        return match ($status) {
            Bitrix24WebhookEvent::STATUS_PENDING => 'warning',
            Bitrix24WebhookEvent::STATUS_PROCESSED => 'success',
            Bitrix24WebhookEvent::STATUS_FAILED => 'danger',
            Bitrix24WebhookEvent::STATUS_IGNORED => 'gray',
            default => 'gray',
        };
    }

    public static function formatSyncLogDirection(?string $direction): string
    {
        return match ($direction) {
            Bitrix24SyncLog::DIRECTION_OUTBOUND => 'Исходящий',
            Bitrix24SyncLog::DIRECTION_SYSTEM => 'Системный',
            default => $direction ?: '—',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function getSyncLogStatusOptions(): array
    {
        return [
            Bitrix24SyncLog::STATUS_SUCCESS => 'Успех',
            Bitrix24SyncLog::STATUS_FAILED => 'Ошибка',
            Bitrix24SyncLog::STATUS_SKIPPED => 'Пропущено',
        ];
    }

    public static function formatSyncLogStatus(?string $status): string
    {
        return static::getSyncLogStatusOptions()[$status] ?? ($status ?: '—');
    }

    public static function getSyncLogStatusTone(?string $status): string
    {
        return match ($status) {
            Bitrix24SyncLog::STATUS_SUCCESS => 'success',
            Bitrix24SyncLog::STATUS_FAILED => 'danger',
            Bitrix24SyncLog::STATUS_SKIPPED => 'warning',
            default => 'gray',
        };
    }
}
