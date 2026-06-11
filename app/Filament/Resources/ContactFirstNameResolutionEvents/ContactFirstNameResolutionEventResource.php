<?php

namespace App\Filament\Resources\ContactFirstNameResolutionEvents;

use App\Filament\Resources\ContactFirstNameResolutionEvents\Pages\ListContactFirstNameResolutionEvents;
use App\Models\Channel;
use App\Models\ContactFirstNameResolutionEvent;
use App\Models\Scenario;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class ContactFirstNameResolutionEventResource extends Resource
{
    protected static ?string $model = ContactFirstNameResolutionEvent::class;

    protected static ?string $modelLabel = 'событие распознавания имени';

    protected static ?string $pluralModelLabel = 'Распознавание имён';

    protected static ?string $navigationLabel = 'Распознавание имён';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('contact_first_name_resolution_events')
            && auth()->user()?->canViewAnalytics() === true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Событие')
                ->schema([
                    TextEntry::make('id')->label('ID')->copyable(),
                    TextEntry::make('event_type')->label('Тип события'),
                    TextEntry::make('source')->label('Источник')->formatStateUsing(fn (?string $state): string => ContactFirstNameResolutionEvent::sourceLabel($state))->badge(),
                    TextEntry::make('result')->label('Результат')->formatStateUsing(fn (?string $state): string => ContactFirstNameResolutionEvent::resultLabel($state))->badge(),
                    TextEntry::make('contact_id')->label('Контакт')->copyable(),
                    TextEntry::make('dialog_id')->label('Диалог')->placeholder('—'),
                    TextEntry::make('channel.name')->label('Канал')->placeholder('—'),
                    TextEntry::make('scenario.name')->label('Сценарий')->placeholder('—'),
                    TextEntry::make('found_first_name')->label('Найдено')->placeholder('—'),
                    TextEntry::make('resolved_first_name')->label('Распознано')->placeholder('—'),
                    TextEntry::make('written_first_name')->label('Записано')->placeholder('—'),
                    TextEntry::make('first_name_resolution_method')->label('Как обработали имя')->placeholder('—'),
                    TextEntry::make('client_text_preview')->label('Ответ клиента')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Технические данные')
                ->visible(fn (): bool => auth()->user()?->canDebugAnalytics() === true)
                ->schema([
                    TextEntry::make('correlation_id')->label('Correlation ID')->copyable(),
                    TextEntry::make('ai_request_id')->label('AI request ID')->placeholder('—'),
                    TextEntry::make('resolution_attempt_event_id')->label('Resolution attempt ID')->placeholder('—'),
                    TextEntry::make('payload')->label('Payload')->state(fn (ContactFirstNameResolutionEvent $record): string => json_encode($record->payload ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->copyable(),
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Тип')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Источник')
                    ->formatStateUsing(fn (?string $state): string => ContactFirstNameResolutionEvent::sourceLabel($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('result')
                    ->label('Результат')
                    ->formatStateUsing(fn (?string $state): string => ContactFirstNameResolutionEvent::resultLabel($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('contact_id')
                    ->label('Контакт')
                    ->sortable()
                    ->copyable(),
                TextColumn::make('client_text_preview')
                    ->label('Ответ клиента')
                    ->limit(50)
                    ->searchable()
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null),
                TextColumn::make('found_first_name')
                    ->label('Найдено')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('resolved_first_name')
                    ->label('Распознано')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('written_first_name')
                    ->label('Записано')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('first_name_resolution_method')
                    ->label('Метод')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Тип события')
                    ->options([
                        ContactFirstNameResolutionEvent::EVENT_TYPE_RESOLUTION_ATTEMPT => 'Распознавание',
                        ContactFirstNameResolutionEvent::EVENT_TYPE_NAME_WRITTEN => 'Запись имени',
                    ]),
                SelectFilter::make('source')
                    ->label('Источник')
                    ->options([
                        ContactFirstNameResolutionEvent::SOURCE_DICTIONARY => 'Справочник',
                        ContactFirstNameResolutionEvent::SOURCE_AI => 'ИИ',
                        ContactFirstNameResolutionEvent::SOURCE_SCENARIO => 'Сценарий',
                        ContactFirstNameResolutionEvent::SOURCE_OPERATOR => 'Оператор',
                        ContactFirstNameResolutionEvent::SOURCE_MESSENGER_PROFILE => 'Профиль мессенджера',
                    ]),
                SelectFilter::make('result')
                    ->label('Результат')
                    ->options([
                        ContactFirstNameResolutionEvent::RESULT_MATCHED => 'Найдено',
                        ContactFirstNameResolutionEvent::RESULT_NOT_FOUND => 'Не найдено',
                        ContactFirstNameResolutionEvent::RESULT_AMBIGUOUS => 'Неоднозначно',
                        ContactFirstNameResolutionEvent::RESULT_MANUAL_REQUIRED => 'Требует уточнения',
                        ContactFirstNameResolutionEvent::RESULT_ACCEPTED => 'Принято',
                        ContactFirstNameResolutionEvent::RESULT_REJECTED => 'Отклонено',
                        ContactFirstNameResolutionEvent::RESULT_ERROR => 'Ошибка',
                        ContactFirstNameResolutionEvent::RESULT_WRITTEN => 'Записано',
                    ]),
                Filter::make('failed')
                    ->label('Только неуспешные')
                    ->query(fn (Builder $query): Builder => $query->failed()),
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(fn (): array => Channel::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('scenario_id')
                    ->label('Сценарий')
                    ->options(fn (): array => Scenario::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('period')
                    ->label('Период')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->extraModalFooterActions([
                        DeleteAction::make('deleteFromView')
                            ->label('Удалить')
                            ->icon(Heroicon::OutlinedTrash)
                            ->color('danger'),
                    ]),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Удалить'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Удалить выбранные'),
            ])
            ->recordActionsColumnLabel('Действия');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactFirstNameResolutionEvents::route('/'),
        ];
    }
}
