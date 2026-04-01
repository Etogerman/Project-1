<?php

namespace Abrikosoff\BitrixBox\OpenLines;

final class Runtime
{
    private const TELEGRAM_CONNECTOR = 'abrikosoff_telegram';

    private const MAX_CONNECTOR = 'abrikosoff_max';

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $config = null;

    public static function register(): void
    {
        if (! class_exists('\\Bitrix\\Main\\EventManager')) {
            return;
        }

        if (! self::isConfigured()) {
            self::log('Abrikosoff Open Lines package skipped registration because config.php is missing or invalid.');

            return;
        }

        $eventManager = \Bitrix\Main\EventManager::getInstance();

        $eventManager->addEventHandler('imconnector', 'OnImConnectorBuildList', [self::class, 'onBuildTelegramConnector']);
        $eventManager->addEventHandler('imconnector', 'OnImConnectorBuildList', [self::class, 'onBuildMaxConnector']);
        $eventManager->addEventHandler('imconnector', 'OnInfoLine', [self::class, 'onInfoLine']);
        $eventManager->addEventHandler('imconnector', 'OnDeleteLine', [self::class, 'onDeleteLine']);
        $eventManager->addEventHandler('imconnector', 'OnSendMessageCustom', [self::class, 'onSendMessageCustom']);
        $eventManager->addEventHandler('imconnector', 'OnUpdateMessageCustom', [self::class, 'onUpdateMessageCustom']);
        $eventManager->addEventHandler('imconnector', 'OnDeleteMessageCustom', [self::class, 'onDeleteMessageCustom']);

        if (self::crmRebindingEnabled()) {
            $eventManager->addEventHandler('imopenlines', 'OnBeforeSessionTransfer', [self::class, 'onBeforeSessionTransfer']);
            $eventManager->addEventHandler('imopenlines', 'OnErrorEventBeforeSessionTransfer', [self::class, 'onErrorEventBeforeSessionTransfer']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function onBuildTelegramConnector(): array
    {
        return self::buildConnectorDefinition(self::TELEGRAM_CONNECTOR);
    }

    /**
     * @return array<string, mixed>
     */
    public static function onBuildMaxConnector(): array
    {
        return self::buildConnectorDefinition(self::MAX_CONNECTOR);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function onInfoLine(...$args): ?array
    {
        $lineId = self::extractLineId($args);

        if ($lineId === '') {
            return null;
        }

        $connectorCode = self::resolveConnectorByLineId($lineId);

        if ($connectorCode === null) {
            return null;
        }

        $meta = self::connectorMeta($connectorCode);

        return [
            'connector_id' => $connectorCode,
            'id' => $lineId,
            'url' => self::laravelOpenlinesCallbackUrl(),
            'url_im' => self::laravelOpenlinesCallbackUrl(),
            'name' => (string) ($meta['line_name'] ?? $meta['name'] ?? $connectorCode),
            'picture' => [
                'url' => self::svgDataUri($connectorCode, false),
            ],
        ];
    }

    public static function onDeleteLine(...$args): void
    {
        $lineId = self::extractLineId($args);

        if ($lineId === '') {
            return;
        }

        $connectorCode = self::resolveConnectorByLineId($lineId);

        if ($connectorCode === null) {
            return;
        }

        if (class_exists('\\Bitrix\\ImConnector\\Status')) {
            \Bitrix\ImConnector\Status::delete($connectorCode, $lineId);
        }
    }

    public static function onSendMessageCustom(...$args): void
    {
        self::forwardImconnectorEvent('OnSendMessageCustom', $args);
    }

    public static function onUpdateMessageCustom(...$args): void
    {
        self::forwardImconnectorEvent('OnUpdateMessageCustom', $args);
    }

    public static function onDeleteMessageCustom(...$args): void
    {
        self::forwardImconnectorEvent('OnDeleteMessageCustom', $args);
    }

    public static function onBeforeSessionTransfer(\Bitrix\Main\Event $event): \Bitrix\Main\EventResult
    {
        $eventParams = $event->getParameters();

        if (! self::crmRebindingEnabled()) {
            return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $eventParams);
        }

        $context = self::buildCrmRebindingContext($eventParams);

        if ($context === null) {
            self::logStructured('crm_rebind_skipped', [
                'reason' => 'line_not_supported_or_context_incomplete',
            ]);

            return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $eventParams);
        }

        if ($context['phone_candidates'] === []) {
            self::logStructured('crm_rebind_skipped', $context + [
                'reason' => 'missing_phone_candidates',
            ]);

            return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $eventParams);
        }

        self::logStructured('crm_rebind_attempted', $context);

        $matchingContactIds = self::findExistingContactIdsByPhones($context['phone_candidates']);

        if ($matchingContactIds === []) {
            self::logStructured('crm_rebind_contact_not_found', $context);

            return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $eventParams);
        }

        if (count($matchingContactIds) > 1) {
            self::logStructured('crm_rebind_ambiguous_match', $context + [
                'matching_contact_ids' => $matchingContactIds,
            ]);

            return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $eventParams);
        }

        $contactId = $matchingContactIds[0];
        $trackerPreview = self::buildTrackerLinkPreview(
            $context['line_id'],
            $context['connector_code'],
            $contactId,
        );

        self::logStructured('crm_rebind_contact_found', $context + [
            'matching_contact_ids' => [$contactId],
            'tracker_preview' => $trackerPreview,
            'status' => 'attach_api_not_implemented',
        ]);

        return new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $eventParams);
    }

    public static function onErrorEventBeforeSessionTransfer(\Bitrix\Main\Event $event): void
    {
        if (! self::crmRebindingEnabled()) {
            return;
        }

        self::logStructured('crm_rebind_transfer_error', [
            'event_parameters' => self::sanitizeForLog($event->getParameters()),
        ]);
    }

    public static function markConnectorReady(string $connectorCode, string $lineId): void
    {
        if ($lineId === '' || ! self::supportsConnector($connectorCode) || ! class_exists('\\Bitrix\\ImConnector\\Status')) {
            return;
        }

        $status = \Bitrix\ImConnector\Status::getInstance($connectorCode, $lineId);
        $status->setActive(true);
        $status->setConnection(true);
        $status->setRegister(true);
        $status->setError(false);

        self::clearLineCache($lineId);
    }

    public static function lineName(string $connectorCode): string
    {
        $meta = self::connectorMeta($connectorCode);

        return (string) ($meta['line_name'] ?? $meta['name'] ?? $connectorCode);
    }

    public static function laravelOpenlinesCallbackUrl(): string
    {
        return trim((string) self::cfg('laravel.openlines_callback_url', ''));
    }

    private static function forwardImconnectorEvent(string $eventName, array $args): void
    {
        [$connectorCode, $lineId, $data] = self::extractImconnectorMessageEvent($args);

        if ($connectorCode === '' || ! self::supportsConnector($connectorCode)) {
            return;
        }

        $payload = [
            'event' => $eventName,
            'auth' => self::authPayload(),
            'data' => [
                'CONNECTOR' => $connectorCode,
                'LINE' => $lineId,
                'DATA' => $data,
            ],
        ];

        self::postJson(self::laravelOpenlinesCallbackUrl(), $payload);
    }

    /**
     * @param  array<int, mixed>  $args
     * @return array{0: string, 1: string, 2: array<int|string, mixed>}
     */
    private static function extractImconnectorMessageEvent(array $args): array
    {
        if (count($args) === 1 && $args[0] instanceof \Bitrix\Main\Event) {
            $event = $args[0];

            return [
                trim((string) $event->getParameter('CONNECTOR')),
                trim((string) $event->getParameter('LINE')),
                self::normalizeArray($event->getParameter('DATA')),
            ];
        }

        return [
            trim((string) ($args[0] ?? '')),
            trim((string) ($args[1] ?? '')),
            self::normalizeArray($args[2] ?? []),
        ];
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private static function extractLineId(array $args): string
    {
        if (count($args) === 1 && $args[0] instanceof \Bitrix\Main\Event) {
            return trim((string) ($args[0]->getParameter('LINE_ID') ?? ''));
        }

        return trim((string) ($args[0] ?? ''));
    }

    private static function resolveConnectorByLineId(string $lineId): ?string
    {
        foreach (self::connectors() as $connectorCode => $meta) {
            if (trim((string) ($meta['line_id'] ?? '')) === trim($lineId)) {
                return (string) $connectorCode;
            }
        }

        return null;
    }

    private static function supportsConnector(string $connectorCode): bool
    {
        return array_key_exists($connectorCode, self::connectors());
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildConnectorDefinition(string $connectorCode): array
    {
        $meta = self::connectorMeta($connectorCode);

        return [
            'ID' => $connectorCode,
            'NAME' => (string) ($meta['name'] ?? $connectorCode),
            'ICON' => self::iconDefinition($connectorCode, false),
            'ICON_DISABLED' => self::iconDefinition($connectorCode, true),
            'COMPONENT' => (string) ($meta['component'] ?? ''),
            'DEL_EXTERNAL_MESSAGES' => true,
            'EDIT_INTERNAL_MESSAGES' => true,
            'DEL_INTERNAL_MESSAGES' => true,
            'NEWSLETTER' => false,
            'NEED_SYSTEM_MESSAGES' => true,
            'NEED_SIGNATURE' => true,
            'CHAT_GROUP' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function connectorMeta(string $connectorCode): array
    {
        $meta = self::connectors()[$connectorCode] ?? null;

        if (! is_array($meta)) {
            throw new \RuntimeException(sprintf(
                'Abrikosoff Open Lines connector `%s` is not configured.',
                $connectorCode,
            ));
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private static function authPayload(): array
    {
        return [
            'domain' => trim((string) self::cfg('auth.portal_domain', '')),
            'member_id' => trim((string) self::cfg('auth.member_id', '')),
            'application_token' => trim((string) self::cfg('auth.application_token', '')),
            'client_endpoint' => trim((string) self::cfg('auth.client_endpoint', '')),
            'server_endpoint' => trim((string) self::cfg('auth.server_endpoint', '')),
            'status' => trim((string) self::cfg('auth.status', 'L')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function iconDefinition(string $connectorCode, bool $disabled): array
    {
        $meta = self::connectorMeta($connectorCode);
        $color = $disabled ? '#5c6470' : (string) ($meta['color'] ?? '#1f87ff');

        return [
            'DATA_IMAGE' => self::svgDataUri($connectorCode, $disabled),
            'COLOR' => $color,
            'SIZE' => '90%',
            'POSITION' => 'center',
        ];
    }

    private static function svgDataUri(string $connectorCode, bool $disabled): string
    {
        $meta = self::connectorMeta($connectorCode);
        $color = $disabled ? '#5c6470' : (string) ($meta['color'] ?? '#1f87ff');
        $label = mb_substr((string) ($meta['label'] ?? 'ABR'), 0, 2);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="70" height="71" viewBox="0 0 70 71"><rect x="4" y="4" width="62" height="62" rx="16" fill="%s"/><text x="35" y="42" text-anchor="middle" font-size="24" font-family="Arial, sans-serif" font-weight="700" fill="#ffffff">%s</text></svg>',
            htmlspecialchars($color, ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES),
        );

        return 'data:image/svg+xml;charset=US-ASCII,'.rawurlencode($svg);
    }

    private static function clearLineCache(string $lineId): void
    {
        if (! class_exists('\\Bitrix\\Main\\Data\\Cache') || ! class_exists('\\Bitrix\\ImConnector\\Library')) {
            return;
        }

        $cache = \Bitrix\Main\Data\Cache::createInstance();
        $cache->clean($lineId, \Bitrix\ImConnector\Library::CACHE_DIR_INFO_CONNECTORS_LINE);
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $configFile = dirname(__DIR__).'/config.php';

        if (! is_file($configFile)) {
            self::$config = [];

            return self::$config;
        }

        $config = require $configFile;
        self::$config = is_array($config) ? $config : [];

        return self::$config;
    }

    /**
     * @return array<string, mixed>
     */
    private static function connectors(): array
    {
        $connectors = self::cfg('connectors', []);

        return is_array($connectors) ? $connectors : [];
    }

    private static function isConfigured(): bool
    {
        return self::laravelOpenlinesCallbackUrl() !== ''
            && trim((string) self::cfg('auth.member_id', '')) !== ''
            && trim((string) self::cfg('auth.application_token', '')) !== ''
            && self::supportsConnector(self::TELEGRAM_CONNECTOR)
            && self::supportsConnector(self::MAX_CONNECTOR);
    }

    /**
     * @param  mixed  $value
     * @return array<int|string, mixed>
     */
    private static function normalizeArray($value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function postJson(string $url, array $payload): void
    {
        if ($url === '') {
            throw new \RuntimeException('Abrikosoff Open Lines callback URL is empty.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \RuntimeException('Failed to encode Abrikosoff Open Lines payload to JSON.');
        }

        if (class_exists('\\Bitrix\\Main\\Web\\HttpClient')) {
            $client = new \Bitrix\Main\Web\HttpClient([
                'socketTimeout' => (int) self::cfg('laravel.timeout_seconds', 15),
                'streamTimeout' => (int) self::cfg('laravel.timeout_seconds', 15),
            ]);
            $client->setHeader('Content-Type', 'application/json', true);

            $response = $client->post($url, $body);
            $status = (int) $client->getStatus();

            if ($response === false || $status >= 400) {
                self::log(sprintf(
                    'Abrikosoff Open Lines callback forward failed: status=%s url=%s body=%s',
                    $status,
                    $url,
                    $body,
                ));

                throw new \RuntimeException('Abrikosoff Open Lines callback forward failed.');
            }

            return;
        }

        if (! function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for Abrikosoff Open Lines callback forwarding.');
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => (int) self::cfg('laravel.connect_timeout_seconds', 5),
            CURLOPT_TIMEOUT => (int) self::cfg('laravel.timeout_seconds', 15),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            self::log(sprintf(
                'Abrikosoff Open Lines callback forward failed: status=%s error=%s url=%s body=%s',
                $status,
                $error,
                $url,
                $body,
            ));

            throw new \RuntimeException('Abrikosoff Open Lines callback forward failed.');
        }
    }

    private static function log(string $message): void
    {
        if (function_exists('AddMessage2Log')) {
            AddMessage2Log($message, 'AbrikosoffOpenLines');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function logStructured(string $event, array $payload): void
    {
        if (! self::crmRebindingLogPayloadEnabled()) {
            unset($payload['event_parameters'], $payload['tracker_preview']);
        }

        self::log(sprintf(
            '%s %s',
            $event,
            json_encode(self::sanitizeForLog($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ));
    }

    private static function crmRebindingEnabled(): bool
    {
        return (bool) self::cfg('crm_rebinding.enabled', false);
    }

    private static function crmRebindingLogPayloadEnabled(): bool
    {
        return (bool) self::cfg('crm_rebinding.log_payload', false);
    }

    /**
     * @param  array<string, mixed>  $eventParams
     * @return array<string, mixed>|null
     */
    private static function buildCrmRebindingContext(array $eventParams): ?array
    {
        $lineId = self::resolveLineIdFromSessionTransferParams($eventParams);
        $connectorCode = self::resolveConnectorByLineId($lineId);

        if ($lineId === '' || $connectorCode === null) {
            return null;
        }

        $phoneCandidates = self::extractPhoneCandidates($eventParams);

        if ($phoneCandidates === []) {
            return [
                'line_id' => $lineId,
                'connector_code' => $connectorCode,
                'session_id' => self::extractSessionId($eventParams),
                'chat_id' => self::extractChatId($eventParams),
                'phone_candidates' => [],
            ];
        }

        return [
            'line_id' => $lineId,
            'connector_code' => $connectorCode,
            'session_id' => self::extractSessionId($eventParams),
            'chat_id' => self::extractChatId($eventParams),
            'phone_candidates' => $phoneCandidates,
            'phone_lookup_variants' => self::buildPhoneLookupVariants($phoneCandidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $eventParams
     */
    private static function resolveLineIdFromSessionTransferParams(array $eventParams): string
    {
        $session = self::normalizeArray($eventParams['session'] ?? []);
        $config = self::normalizeArray($eventParams['config'] ?? []);

        foreach ([
            $session['CONFIG_ID'] ?? null,
            $config['ID'] ?? null,
            $config['CONFIG_ID'] ?? null,
        ] as $candidate) {
            $lineId = trim((string) $candidate);

            if ($lineId !== '') {
                return $lineId;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $eventParams
     */
    private static function extractSessionId(array $eventParams): string
    {
        $session = self::normalizeArray($eventParams['session'] ?? []);

        return trim((string) ($session['ID'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $eventParams
     */
    private static function extractChatId(array $eventParams): string
    {
        $session = self::normalizeArray($eventParams['session'] ?? []);

        foreach ([
            $session['CHAT_ID'] ?? null,
            self::extractChatIdFromChatObject($eventParams['chat'] ?? null),
        ] as $candidate) {
            $chatId = trim((string) $candidate);

            if ($chatId !== '') {
                return $chatId;
            }
        }

        return '';
    }

    /**
     * @param  mixed  $chat
     */
    private static function extractChatIdFromChatObject($chat): string
    {
        if (! is_object($chat)) {
            return '';
        }

        foreach (['getChatId', 'getId'] as $method) {
            if (method_exists($chat, $method)) {
                $value = $chat->{$method}();

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $eventParams
     * @return list<string>
     */
    private static function extractPhoneCandidates(array $eventParams): array
    {
        $phoneCandidates = [];

        self::collectPhoneCandidates($eventParams, $phoneCandidates);

        return array_values(array_unique($phoneCandidates));
    }

    /**
     * @param  mixed  $value
     * @param  array<int, string>  $phoneCandidates
     */
    private static function collectPhoneCandidates($value, array &$phoneCandidates): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && stripos($key, 'PHONE') !== false) {
                    $normalized = self::normalizePhone($item);

                    if ($normalized !== '') {
                        $phoneCandidates[] = $normalized;
                    }
                }

                self::collectPhoneCandidates($item, $phoneCandidates);
            }

            return;
        }

        if (is_object($value)) {
            foreach (['toArray', 'getData', 'getFields'] as $method) {
                if (! method_exists($value, $method)) {
                    continue;
                }

                $result = $value->{$method}();

                if (is_array($result)) {
                    self::collectPhoneCandidates($result, $phoneCandidates);
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $phones
     * @return list<int>
     */
    private static function findExistingContactIdsByPhones(array $phones): array
    {
        if ($phones === [] || ! class_exists('\\CCrmFieldMulti')) {
            return [];
        }

        $contactIds = [];
        $lookupVariants = self::buildPhoneLookupVariants($phones);

        foreach ($lookupVariants as $phone) {
            $rows = \CCrmFieldMulti::GetListEx(
                [],
                [
                    'ENTITY_ID' => 'CONTACT',
                    'TYPE_ID' => 'PHONE',
                    'VALUE' => $phone,
                ]
            );

            while ($row = $rows->Fetch()) {
                $contactId = (int) ($row['ELEMENT_ID'] ?? 0);

                if ($contactId > 0) {
                    $contactIds[$contactId] = $contactId;
                }
            }
        }

        return array_values($contactIds);
    }

    /**
     * @param  array<int, string>  $phones
     * @return list<string>
     */
    private static function buildPhoneLookupVariants(array $phones): array
    {
        $variants = [];

        foreach ($phones as $phone) {
            $normalized = self::normalizePhone($phone);

            if ($normalized === '') {
                continue;
            }

            $digits = ltrim($normalized, '+');

            foreach ([
                $normalized,
                $digits,
            ] as $variant) {
                if ($variant !== '') {
                    $variants[$variant] = $variant;
                }
            }

            if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
                $alternate = ($digits[0] === '7' ? '8' : '7').substr($digits, 1);

                $variants[$alternate] = $alternate;
                $variants['+'.$alternate] = '+'.$alternate;
            }
        }

        return array_values($variants);
    }

    /**
     * @return array<string, string>|null
     */
    private static function buildTrackerLinkPreview(string $lineId, string $connectorCode, int $contactId): ?array
    {
        if ($lineId === '' || $connectorCode === '' || $contactId <= 0) {
            return null;
        }

        if (! class_exists('\\Bitrix\\Main\\DI\\ServiceLocator') || ! class_exists('\\CCrmOwnerType')) {
            return null;
        }

        try {
            /** @var mixed $tracker */
            $tracker = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('ImOpenLines.Services.Tracker');

            if (! is_object($tracker) || ! method_exists($tracker, 'getMessengerLink')) {
                return null;
            }

            $links = $tracker->getMessengerLink((int) $lineId, $connectorCode, [[
                'ENTITY_TYPE_ID' => \CCrmOwnerType::Contact,
                'ENTITY_ID' => $contactId,
            ]]);

            return is_array($links) ? array_filter([
                'web' => isset($links['web']) ? trim((string) $links['web']) : '',
                'mob' => isset($links['mob']) ? trim((string) $links['mob']) : '',
            ], static fn (string $value): bool => $value !== '') : null;
        } catch (\Throwable $throwable) {
            self::logStructured('crm_rebind_tracker_preview_failed', [
                'line_id' => $lineId,
                'connector_code' => $connectorCode,
                'contact_id' => $contactId,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function sanitizeForLog($value, string $key = '')
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = self::sanitizeForLog($item, (string) $key);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return '[object '.get_class($value).']';
        }

        if (! is_scalar($value) && $value !== null) {
            return '['.gettype($value).']';
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
            return $value;
        }

        if (self::looksLikePhoneLogKey($key)) {
            return self::maskPhoneForLog((string) $value);
        }

        if (self::isSensitiveLogKey($key)) {
            return '*** redacted ***';
        }

        return $value;
    }

    /**
     * @param  mixed  $value
     */
    private static function normalizePhone($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return '';
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        return $hasPlus ? '+'.$digits : $digits;
    }

    private static function maskPhoneForLog(string $value): string
    {
        $normalized = self::normalizePhone($value);

        if ($normalized === '') {
            return '';
        }

        $digits = ltrim($normalized, '+');
        $visibleTail = substr($digits, -4);
        $maskedHead = str_repeat('*', max(strlen($digits) - strlen($visibleTail), 0));

        return ($normalized[0] === '+' ? '+' : '').$maskedHead.$visibleTail;
    }

    private static function looksLikePhoneLogKey(string $key): bool
    {
        return stripos($key, 'phone') !== false;
    }

    private static function isSensitiveLogKey(string $key): bool
    {
        $sensitiveKeys = [
            'application_token',
            'member_id',
        ];

        return in_array(strtolower($key), $sensitiveKeys, true);
    }

    /**
     * @return mixed
     */
    private static function cfg(string $path, $default = null)
    {
        $segments = array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== '');
        $value = self::config();

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
