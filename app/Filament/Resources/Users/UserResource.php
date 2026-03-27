<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Сотрудник';

    protected static ?string $pluralModelLabel = 'Сотрудники';

    protected static ?string $navigationLabel = 'Сотрудники';

    protected static string|UnitEnum|null $navigationGroup = 'Команда';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof User) {
            return parent::getRecordTitle($record);
        }

        return sprintf('#%d %s', $record->id, $record->name);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Сотрудник')
                    ->schema([
                        TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->mutateStateForValidationUsing(fn (?string $state): ?string => static::normalizeEmail($state))
                            ->dehydrateStateUsing(fn (?string $state): ?string => static::normalizeEmail($state))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->disabled(fn (?User $record, string $operation): bool => $operation === 'edit' && auth()->id() === $record?->id)
                            ->inline(false),
                        Toggle::make('is_admin')
                            ->label('Администратор')
                            ->default(false)
                            ->disabled(fn (?User $record, string $operation): bool => $operation === 'edit' && auth()->id() === $record?->id)
                            ->inline(false),
                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->same('password_confirmation')
                            ->minLength(8)
                            ->helperText('Оставьте пустым, если пароль не нужно менять.'),
                        TextInput::make('password_confirmation')
                            ->label('Подтверждение пароля')
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Сотрудник')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        TextEntry::make('name')
                            ->label('Имя'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable(),
                        IconEntry::make('is_active')
                            ->label('Активен')
                            ->boolean(),
                        IconEntry::make('is_admin')
                            ->label('Администратор')
                            ->boolean(),
                        TextEntry::make('created_at')
                            ->label('Создан')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Обновлён')
                            ->dateTime('d.m.Y H:i'),
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
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('is_active')
                    ->label('Активен')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключен')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('is_admin')
                    ->label('Админ')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Статус')
                    ->placeholder('Все')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только отключённые'),
                TernaryFilter::make('is_admin')
                    ->label('Роль')
                    ->placeholder('Все')
                    ->trueLabel('Только администраторы')
                    ->falseLabel('Только сотрудники'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Сотрудники ещё не добавлены')
            ->emptyStateDescription('Добавьте первого сотрудника команды.')
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->beforeFormValidated(function (EditAction $action): void {
                        $record = $action->getRecord();

                        if (! $record instanceof User) {
                            return;
                        }

                        static::guardAgainstSelfLockout($record, $action->getRawData());
                    })
                    ->using(function (array $data, User $record): void {
                        static::guardAgainstSelfLockout($record, $data);

                        $record->update($data);
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }

    protected static function normalizeEmail(?string $value): ?string
    {
        if (! filled($value)) {
            return $value;
        }

        return mb_strtolower(trim($value));
    }

    protected static function guardAgainstSelfLockout(User $record, array $data): void
    {
        if (auth()->id() !== $record->id) {
            return;
        }

        $messages = [];

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $messages['is_active'] = 'Нельзя отключить самого себя.';
        }

        if (array_key_exists('is_admin', $data) && ! $data['is_admin']) {
            $messages['is_admin'] = 'Нельзя снять у себя права администратора.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
