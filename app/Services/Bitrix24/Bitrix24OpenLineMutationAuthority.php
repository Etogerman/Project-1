<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;

final readonly class Bitrix24OpenLineMutationAuthority
{
    public const SCOPE_LINE_RUNTIME = 'line_runtime';

    public const SCOPE_CONNECTOR_REGISTRATION = 'connector_registration';

    public function __construct(
        public string $portalDomain,
        public string $lineId,
        public string $ownerProfileKey,
        public string $ownerCallbackBaseUrl,
        public string $connectorCode,
        public string $connectorType,
        public string $scope,
        public string $leaseToken,
        public Bitrix24OpenLineRouteLeaseDeadline $deadline,
        public string $operationId,
        public string $operationType,
        public ?int $routeId,
        public ?int $expectedStateVersion,
        public ?string $createIntentVersion = null,
    ) {
        if (! in_array($scope, [self::SCOPE_LINE_RUNTIME, self::SCOPE_CONNECTOR_REGISTRATION], true)
            || preg_match('/^[a-f0-9]{64}$/', $leaseToken) !== 1
            || preg_match('/^[0-9a-f-]{36}$/', $operationId) !== 1
            || ! Bitrix24OpenLineRoute::isValidConnectorCode($connectorCode)
            || ! in_array($connectorType, ['telegram', 'max'], true)
            || ($lineId !== '' && ! Bitrix24OpenLineRoute::isValidLineId($lineId))
        ) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_authority_invalid',
                'Контекст authority для Open Lines mutation сформирован некорректно.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function assertAllows(
        Bitrix24Connection $connection,
        string $method,
        array $params,
        ?Bitrix24OpenLineMutationTarget $target = null,
    ): void {
        $method = mb_strtolower(trim($method));
        $allowedScopes = Bitrix24OpenLineRestMethodPolicy::allowedScopes($method);

        if (! in_array($this->scope, $allowedScopes, true)) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_scope_mismatch',
                sprintf('Lease scope %s не разрешает REST-метод %s.', $this->scope, $method),
            );
        }

        if ($this->normalizePortal((string) $connection->portal_domain) !== $this->normalizePortal($this->portalDomain)) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_portal_mismatch',
                'Authority относится к другому порталу Bitrix24.',
            );
        }

        if ($method === 'imopenlines.config.add') {
            if ($this->createIntentVersion === null || $this->lineId !== '') {
                throw new Bitrix24OpenLineMutationAuthorityException(
                    'openlines_create_intent_missing',
                    'Создание открытой линии требует отдельного версионированного create intent.',
                );
            }

            return;
        }

        if ($this->lineId === '') {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_line_missing',
                'Authority существующего маршрута не содержит канонический LINE_ID.',
            );
        }

        $identity = Bitrix24OpenLineRestMethodPolicy::requiredPayloadIdentity($method);
        $this->assertMutationTargetMatches(
            $params,
            $target,
            $identity['chat_target'],
        );
        $this->assertScalarMatches(
            $params,
            ['LINE', 'CONFIG_ID'],
            $this->lineId,
            'LINE_ID',
            $identity['line'],
        );
        $this->assertScalarMatches(
            $params,
            ['CONNECTOR', 'ID'],
            $this->connectorCode,
            'connector_code',
            $identity['connector'],
        );
        $this->assertUserCodeMatches($params, $identity['user_code']);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertMutationTargetMatches(
        array $params,
        ?Bitrix24OpenLineMutationTarget $target,
        bool $required,
    ): void {
        if (! $target instanceof Bitrix24OpenLineMutationTarget) {
            if ($required) {
                throw new Bitrix24OpenLineMutationAuthorityException(
                    'openlines_mutation_target_missing',
                    'REST-вызов не содержит проверенную цель маршрута и Bitrix chat.',
                );
            }

            return;
        }

        $this->assertSameRoute(
            $target->portalDomain,
            $target->connectorCode,
            $target->lineId,
        );

        $chatId = $params['CHAT_ID'] ?? null;

        if (! is_scalar($chatId)) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_identity_missing',
                'REST payload не содержит обязательный CHAT_ID.',
            );
        }

        $chatId = (string) $chatId;

        if (trim($chatId) !== $chatId || $chatId !== $target->chatId) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_identity_mismatch',
                'REST payload содержит CHAT_ID другой цели mutation.',
            );
        }
    }

    public function assertSameRoute(
        string $portalDomain,
        string $connectorCode,
        string $lineId,
    ): void {
        if ($this->normalizePortal($portalDomain) !== $this->normalizePortal($this->portalDomain)
            || trim($connectorCode) !== $this->connectorCode
            || ! Bitrix24OpenLineRoute::isValidLineId($lineId)
            || $lineId !== $this->lineId
        ) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_route_mismatch',
                'Текущий authority относится к другому маршруту открытой линии.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $keys
     */
    private function assertScalarMatches(
        array $params,
        array $keys,
        string $expected,
        string $label,
        bool $required,
    ): void {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            $actual = is_scalar($params[$key]) ? (string) $params[$key] : '';

            if ($actual !== $expected) {
                throw new Bitrix24OpenLineMutationAuthorityException(
                    'openlines_mutation_identity_mismatch',
                    sprintf('REST payload содержит другой %s.', $label),
                );
            }

            return;
        }

        if ($required) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_identity_missing',
                sprintf('REST payload не содержит обязательный %s.', $label),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertUserCodeMatches(array $params, bool $required): void
    {
        $userCode = $params['USER_CODE'] ?? null;

        if (! is_scalar($userCode)) {
            if ($required) {
                throw new Bitrix24OpenLineMutationAuthorityException(
                    'openlines_mutation_identity_missing',
                    'REST payload не содержит обязательный USER_CODE.',
                );
            }

            return;
        }

        $userCode = (string) $userCode;

        if (trim($userCode) !== $userCode) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_identity_mismatch',
                'USER_CODE содержит неканоническую идентичность маршрута.',
            );
        }

        $parts = explode('|', $userCode, 3);

        if (count($parts) < 2) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_identity_mismatch',
                'USER_CODE не содержит connector/LINE_ID.',
            );
        }

        if ($parts[0] !== $this->connectorCode
            || ! Bitrix24OpenLineRoute::isValidLineId($parts[1])
            || $parts[1] !== $this->lineId
        ) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_identity_mismatch',
                'USER_CODE относится к другому connector/LINE_ID.',
            );
        }
    }

    private function normalizePortal(string $portalDomain): string
    {
        return mb_strtolower(rtrim(trim($portalDomain), '.'));
    }
}
