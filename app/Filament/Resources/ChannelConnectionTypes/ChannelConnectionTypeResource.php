<?php

namespace App\Filament\Resources\ChannelConnectionTypes;

use App\Filament\Resources\ChannelConnectionTypes\Pages\ManageChannelConnectionTypes;
use App\Models\Channel;
use App\Models\ChannelConnectionType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ChannelConnectionTypeResource extends Resource
{
    protected static ?string $model = ChannelConnectionType::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Тип подключения';

    protected static ?string $pluralModelLabel = 'Типы подключений';

    protected static ?string $navigationLabel = 'Типы подключений';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 16;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Тип подключения')
                    ->description('Справочник определяет, какие настройки и маршрутизация доступны конкретному типу канала.')
                    ->schema([
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->rules(['alpha_dash:ascii'])
                            ->unique(ignoreRecord: true)
                            ->maxLength(64),
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        Select::make('platform')
                            ->label('Платформа')
                            ->options(Channel::platformOptions())
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false),
                        Select::make('connection_kind')
                            ->label('Режим')
                            ->options([
                                Channel::CONNECTION_TYPE_BOT => 'Bot',
                                Channel::CONNECTION_TYPE_ACCOUNT => 'Account',
                            ])
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->inline(false),
                        Toggle::make('supports_open_lines')
                            ->label('Open Lines')
                            ->default(false)
                            ->inline(false),
                        Toggle::make('supports_auto_setup')
                            ->label('Автонастройка')
                            ->default(false)
                            ->inline(false),
                        TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(100)
                            ->required(),
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
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('platform')
                    ->label('Платформа')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Channel::platformOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('connection_kind')
                    ->label('Режим')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Channel::CONNECTION_TYPE_BOT => 'Bot',
                        Channel::CONNECTION_TYPE_ACCOUNT => 'Account',
                        default => $state,
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('supports_open_lines')
                    ->label('Open Lines')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('supports_auto_setup')
                    ->label('Автонастройка')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('channels_count')
                    ->label('Каналов')
                    ->counts('channels')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('Типы подключений ещё не настроены')
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChannelConnectionTypes::route('/'),
        ];
    }
}
