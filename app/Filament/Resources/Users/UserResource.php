<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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
                Section::make('Основное')
                    ->description('Базовые данные сотрудника для входа и отображения в админке.')
                    ->extraAttributes(['class' => 'ac-user-form-section ac-user-form-section--profile'])
                    ->schema([
                        TextInput::make('name')
                            ->label('Имя')
                            ->extraFieldWrapperAttributes(['class' => 'ac-user-form-field'])
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->extraFieldWrapperAttributes(['class' => 'ac-user-form-field'])
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->mutateStateForValidationUsing(fn (?string $state): ?string => static::normalizeEmail($state))
                            ->dehydrateStateUsing(fn (?string $state): ?string => static::normalizeEmail($state))
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Доступ')
                    ->description('Управление активностью учётной записи и административными правами.')
                    ->extraAttributes(['class' => 'ac-user-form-section ac-user-form-section--access'])
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->extraFieldWrapperAttributes(['class' => 'ac-user-form-toggle'])
                            ->disabled(fn (?User $record, string $operation): bool => $operation === 'edit' && auth()->id() === $record?->id)
                            ->helperText('Отключённый сотрудник не сможет войти в панель.')
                            ->inline(false),
                        Toggle::make('is_admin')
                            ->label('Администратор')
                            ->default(false)
                            ->extraFieldWrapperAttributes(['class' => 'ac-user-form-toggle'])
                            ->disabled(fn (?User $record, string $operation): bool => ($operation === 'edit' && auth()->id() === $record?->id)
                                || $record?->isSuperadmin() === true)
                            ->helperText(fn (?User $record): string => $record?->isSuperadmin()
                                ? 'Роль суперадминистратора закреплена отдельно и не меняется через обычную форму сотрудника.'
                                : 'Администратор управляет сотрудниками и настройками панели.')
                            ->inline(false),
                    ])
                    ->columns(2),
                Section::make('Пароль')
                    ->description(fn (string $operation): string => $operation === 'create'
                        ? 'Укажите пароль для нового сотрудника.'
                        : 'Оставьте поля пустыми, если пароль менять не нужно.')
                    ->extraAttributes(['class' => 'ac-user-form-section ac-user-form-section--password'])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->extraFieldWrapperAttributes(['class' => 'ac-user-form-field'])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->same('password_confirmation')
                            ->minLength(8)
                            ->helperText('Оставьте пустым, если пароль не нужно менять.'),
                        TextInput::make('password_confirmation')
                            ->label('Подтверждение пароля')
                            ->password()
                            ->revealable()
                            ->extraFieldWrapperAttributes(['class' => 'ac-user-form-field'])
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
                        ViewEntry::make('user_overview')
                            ->hiddenLabel()
                            ->view('filament.users.partials.user-overview')
                            ->viewData(fn (User $record): array => static::buildUserOverviewViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
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
                    ->label('Статус')
                    ->badge()
                    ->extraAttributes(['class' => 'ac-user-table-badge'])
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключён')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->extraAttributes(['class' => 'ac-user-table-badge'])
                    ->formatStateUsing(fn (string $state, User $record): string => static::roleLabel($record))
                    ->color(fn (string $state, User $record): string => static::roleTone($record))
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
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        User::ROLE_SUPERADMIN => 'Суперадминистратор',
                        User::ROLE_ADMIN => 'Администратор',
                        User::ROLE_EMPLOYEE => 'Сотрудник',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Сотрудники ещё не добавлены')
            ->emptyStateDescription('Добавьте первого сотрудника команды.')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                ViewAction::make()
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Просмотр')
                    ->modalWidth(Width::FourExtraLarge)
                    ->extraAttributes(['class' => 'ac-user-table-action']),
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Изменить')
                    ->modalWidth(Width::FourExtraLarge)
                    ->extraAttributes(['class' => 'ac-user-table-action'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->extraModalWindowAttributes(['class' => 'ac-user-form-modal'])
                    ->beforeFormValidated(function (EditAction $action): void {
                        $record = $action->getRecord();

                        if (! $record instanceof User) {
                            return;
                        }

                        static::guardAgainstSelfLockout($record, $action->getRawData());
                    })
                    ->using(function (array $data, User $record): void {
                        static::updateUserFromFormData($record, $data);
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createUserFromFormData(array $data): User
    {
        $user = new User();

        static::persistUserFromFormData($user, $data);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateUserFromFormData(User $record, array $data): void
    {
        static::guardAgainstSelfLockout($record, $data);
        $data = static::preserveProtectedRole($record, $data);

        static::persistUserFromFormData($record, $data);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     idLabel: string,
     *     activeLabel: string,
     *     activeTone: string,
     *     roleLabel: string,
     *     roleTone: string,
     *     createdAtLabel: string,
     *     updatedAtLabel: string
     * }
     */
    protected static function buildUserOverviewViewData(User $record): array
    {
        return [
            'name' => $record->name,
            'email' => $record->email,
            'idLabel' => (string) $record->id,
            'activeLabel' => $record->is_active ? 'Активен' : 'Отключён',
            'activeTone' => $record->is_active ? 'success' : 'danger',
            'roleLabel' => static::roleLabel($record),
            'roleTone' => static::roleTone($record),
            'createdAtLabel' => static::formatUserTimestamp($record->created_at),
            'updatedAtLabel' => static::formatUserTimestamp($record->updated_at),
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function preserveProtectedRole(User $record, array $data): array
    {
        if (! $record->isSuperadmin()) {
            return $data;
        }

        $data['role'] = User::ROLE_SUPERADMIN;
        $data['is_admin'] = true;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function persistUserFromFormData(User $user, array $data): void
    {
        $protectedData = Arr::only($data, ['is_admin']);
        $regularData = Arr::except($data, ['is_admin', 'role']);

        $user->fill($regularData);

        if ($protectedData !== []) {
            $user->forceFill($protectedData);
        }

        $user->save();
    }

    protected static function roleLabel(User $record): string
    {
        return match ($record->resolvedRole()) {
            User::ROLE_SUPERADMIN => 'Суперадминистратор',
            User::ROLE_ADMIN => 'Администратор',
            default => 'Сотрудник',
        };
    }

    protected static function roleTone(User $record): string
    {
        return match ($record->resolvedRole()) {
            User::ROLE_SUPERADMIN => 'danger',
            User::ROLE_ADMIN => 'warning',
            default => 'neutral',
        };
    }

    protected static function formatUserTimestamp(mixed $timestamp): string
    {
        return $timestamp?->format('d.m.Y H:i') ?? '—';
    }
}
