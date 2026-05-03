<?php

namespace App\Console\Commands;

use App\Data\Bitrix24\Bitrix24OpenLinesDialogBindingData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24Connection;
use App\Models\Dialog;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24ApiException;
use App\Services\Bitrix24\ResolveBitrix24LiveChatKeyAction;
use App\Services\Bitrix24\ResolveBitrix24OpenLinesDialogBindingAction;
use App\Services\Bitrix24\ResolveBitrix24OpenLinesRouteAction;
use App\Services\Bitrix24\ResolveCurrentBitrix24ConnectionAction;
use Illuminate\Console\Command;
use Throwable;

class Bitrix24BindOpenLineDialogCommand extends Command
{
    protected $signature = 'bitrix24:bind-openline-dialog
        {dialog : ID диалога Abrikosoff}
        {--user-code= : USER_CODE из Bitrix24, можно с префиксом imol|}
        {--connector= : Код соединителя Bitrix24}
        {--line= : ID открытой линии Bitrix24}
        {--connector-chat= : Третья часть USER_CODE, например abrikosoff-dialog:23}
        {--connector-user= : Четвёртая часть USER_CODE из старой ОЛ}
        {--chat-id= : Ожидаемый Bitrix chat id старой ОЛ}
        {--dry-run : Проверить привязку без сохранения}';

    protected $description = 'Bind an Abrikosoff dialog to a verified legacy Bitrix24 Open Lines USER_CODE.';

    public function __construct(
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveRouteAction,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnectionAction,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveLiveChatKeyAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dialog = Dialog::query()
            ->with(['contact', 'channel', 'currentContactIdentity', 'bitrix24OpenLineRoute'])
            ->find($this->dialogId());

        if (! $dialog instanceof Dialog) {
            $this->error('Диалог #'.$this->dialogId().' не найден.');

            return self::FAILURE;
        }

        try {
            $route = $this->resolveRouteAction->handle($dialog);
            $connection = $this->resolveCurrentConnectionAction->handle();
        } catch (Throwable $throwable) {
            $this->error('Не удалось определить маршрут Bitrix24 Open Lines: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $binding = $this->resolveBindingOption($dialog, $route);

        if (! $binding instanceof Bitrix24OpenLinesDialogBindingData) {
            return self::FAILURE;
        }

        if ($binding->connectorCode !== $route->connectorCode || $binding->lineId !== $route->lineId) {
            $this->error(sprintf(
                'USER_CODE относится к маршруту [%s:%s], а диалог сейчас привязан к [%s:%s].',
                $binding->connectorCode,
                $binding->lineId,
                $route->connectorCode,
                $route->lineId,
            ));

            return self::FAILURE;
        }

        $expectedConnectorChatId = $this->resolveLiveChatKeyAction->handle($dialog);

        if ($binding->connectorChatId !== $expectedConnectorChatId) {
            $this->error(sprintf(
                'USER_CODE содержит connector chat [%s], а для диалога #%d ожидается [%s]. Привязка не сохранена.',
                $binding->connectorChatId,
                $dialog->id,
                $expectedConnectorChatId,
            ));

            return self::FAILURE;
        }

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.dialog.get',
                ['USER_CODE' => $binding->userCode],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $this->error('Проверка USER_CODE в Bitrix24 завершилась транспортной ошибкой: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful || ! is_array($response->result)) {
            $this->error('Bitrix24 не подтвердил USER_CODE: '.($response->errorMessage ?? 'Unknown error.'));

            return self::FAILURE;
        }

        $resolvedChatId = $this->extractChatId($response->result);

        if ($resolvedChatId === null) {
            $this->error('Bitrix24 подтвердил USER_CODE, но не вернул ID чата.');

            return self::FAILURE;
        }

        $expectedChatId = $this->stringOption('chat-id');

        if ($expectedChatId !== null && $expectedChatId !== $resolvedChatId) {
            $this->error(sprintf(
                'Bitrix24 вернул chat id [%s], но ожидался [%s]. Привязка не сохранена.',
                $resolvedChatId,
                $expectedChatId,
            ));

            return self::FAILURE;
        }

        if (! $this->assertContactBindingMatches($dialog, $response->result)) {
            return self::FAILURE;
        }

        if (! $this->assertChatIsActiveForContact($dialog, $connection, $route, $resolvedChatId)) {
            return self::FAILURE;
        }

        $this->table(
            ['Поле', 'Значение'],
            [
                ['Dialog', '#'.$dialog->id],
                ['USER_CODE', $binding->userCode],
                ['Connector', $binding->connectorCode],
                ['Line', $binding->lineId],
                ['Connector chat', $binding->connectorChatId],
                ['Connector user', $binding->connectorUserId],
                ['Bitrix chat id', $resolvedChatId],
            ],
        );

        if ($this->option('dry-run')) {
            $this->info('Проверка успешна. Привязка не сохранена из-за --dry-run.');

            return self::SUCCESS;
        }

        $updates = [
            'bitrix24_open_line_user_code_override' => $binding->userCode,
            'bitrix24_open_line_resolved_chat_id_override' => $resolvedChatId,
            'bitrix24_open_line_binding_verified_at' => now(),
            'bitrix24_live_chat_id' => $binding->connectorChatId,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ];

        if ($dialog->bitrix24_open_line_route_id === null && $route->routeId !== null) {
            $updates['bitrix24_open_line_route_id'] = $route->routeId;
        }

        $dialog->forceFill($updates)->save();

        $this->info('Привязка старой Bitrix24 ОЛ сохранена.');

        return self::SUCCESS;
    }

    private function dialogId(): int
    {
        return (int) $this->argument('dialog');
    }

    private function resolveBindingOption(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
    ): ?Bitrix24OpenLinesDialogBindingData {
        $userCode = $this->stringOption('user-code');

        if ($userCode !== null) {
            $binding = $this->resolveDialogBindingAction->parseUserCode($userCode);

            if (! $binding instanceof Bitrix24OpenLinesDialogBindingData) {
                $this->error('USER_CODE должен иметь формат connector|line|connector_chat|connector_user или imol|connector|line|connector_chat|connector_user.');

                return null;
            }

            return $binding;
        }

        $connectorCode = $this->stringOption('connector') ?? $route->connectorCode;
        $lineId = $this->stringOption('line') ?? $route->lineId;
        $connectorChatId = $this->stringOption('connector-chat') ?? $this->resolveLiveChatKeyAction->handle($dialog);
        $connectorUserId = $this->stringOption('connector-user');

        if ($connectorUserId === null) {
            $this->error('Передайте либо --user-code, либо --connector-user. Connector и line по умолчанию берутся из текущего маршрута диалога.');

            return null;
        }

        return $this->resolveDialogBindingAction->parseUserCode(implode('|', [
            $connectorCode,
            $lineId,
            $connectorChatId,
            $connectorUserId,
        ]));
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractChatId(array $result): ?string
    {
        foreach (['id', 'ID', 'CHAT_ID', 'chat_id', 'chatId'] as $key) {
            $value = $result[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertContactBindingMatches(Dialog $dialog, array $result): bool
    {
        $dialog->loadMissing('contact');

        $expectedContactId = is_scalar($dialog->contact?->bitrix24_contact_id ?? null)
            ? trim((string) $dialog->contact->bitrix24_contact_id)
            : '';

        if ($expectedContactId === '') {
            $this->error('Диалог нельзя привязать к старой ОЛ до синхронизации контакта с Bitrix24 CONTACT.');

            return false;
        }

        $contactIds = $this->extractCrmContactIds($result['entity_data_2'] ?? null);

        if ($contactIds === []) {
            $this->error('Bitrix24 подтвердил USER_CODE, но в ответе нет CONTACT-привязки.');

            return false;
        }

        if (! isset($contactIds[$expectedContactId])) {
            $this->error(sprintf(
                'USER_CODE привязан к CONTACT [%s], а наш контакт ожидает CONTACT [%s]. Привязка не сохранена.',
                implode(', ', array_keys($contactIds)),
                $expectedContactId,
            ));

            return false;
        }

        return true;
    }

    private function assertChatIsActiveForContact(
        Dialog $dialog,
        Bitrix24Connection $connection,
        Bitrix24OpenLinesRouteData $route,
        string $resolvedChatId,
    ): bool {
        $dialog->loadMissing('contact');

        $expectedContactId = is_scalar($dialog->contact?->bitrix24_contact_id ?? null)
            ? trim((string) $dialog->contact->bitrix24_contact_id)
            : '';

        if ($expectedContactId === '') {
            $this->error('Диалог нельзя привязать к старой ОЛ до синхронизации контакта с Bitrix24 CONTACT.');

            return false;
        }

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.crm.chat.get',
                [
                    'CRM_ENTITY_TYPE' => 'CONTACT',
                    'CRM_ENTITY' => $expectedContactId,
                    'ACTIVE_ONLY' => 'Y',
                ],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $this->error('Проверка активных чатов CONTACT в Bitrix24 завершилась транспортной ошибкой: '.$exception->getMessage());

            return false;
        }

        if (! $response->successful || ! is_array($response->result)) {
            $this->error('Bitrix24 не подтвердил список активных чатов CONTACT: '.($response->errorMessage ?? 'Unknown error.'));

            return false;
        }

        foreach ($this->extractChatRows($response->result) as $chat) {
            if ($this->extractChatId($chat) !== $resolvedChatId) {
                continue;
            }

            $connectorId = $this->extractConnectorId($chat);

            if ($connectorId !== null && $connectorId !== $route->connectorCode) {
                $this->error(sprintf(
                    'Bitrix24 вернул chat id [%s] активным, но его connector [%s] не совпадает с маршрутом [%s]. Привязка не сохранена.',
                    $resolvedChatId,
                    $connectorId,
                    $route->connectorCode,
                ));

                return false;
            }

            return true;
        }

        $this->error(sprintf(
            'Bitrix24 подтвердил USER_CODE, но chat id [%s] не найден среди активных чатов CONTACT [%s]. Такой binding не подходит для отправки через imopenlines.crm.message.add.',
            $resolvedChatId,
            $expectedContactId,
        ));

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function extractCrmContactIds(mixed $crmBinding): array
    {
        if (! is_scalar($crmBinding)) {
            return [];
        }

        $parts = array_map('trim', explode('|', (string) $crmBinding));
        $contactIds = [];

        for ($index = 0; $index + 1 < count($parts); $index += 2) {
            if (strtoupper($parts[$index]) !== 'CONTACT') {
                continue;
            }

            $contactId = trim($parts[$index + 1]);

            if ($contactId !== '' && $contactId !== '0') {
                $contactIds[$contactId] = true;
            }
        }

        return $contactIds;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function extractChatRows(array $result): array
    {
        $chatRows = null;

        if (array_is_list($result)) {
            $chatRows = $result;
        } else {
            foreach (['chats', 'CHATS', 'result', 'RESULT', 'items', 'ITEMS'] as $key) {
                $value = $result[$key] ?? null;

                if (is_array($value)) {
                    $chatRows = $value;
                    break;
                }
            }

            if ($chatRows === null && $this->extractChatId($result) !== null) {
                $chatRows = [$result];
            }
        }

        if (! is_array($chatRows)) {
            return [];
        }

        return array_values(array_filter(
            $chatRows,
            fn (mixed $row): bool => is_array($row) && $this->extractChatId($row) !== null,
        ));
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function extractConnectorId(array $chat): ?string
    {
        foreach (['CONNECTOR_ID', 'connector_id', 'connectorId', 'CONNECTOR'] as $key) {
            $value = $chat[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
