<?php

namespace App\Services\Bitrix24;

final class Bitrix24OpenLineRestMethodPolicy
{
    /**
     * @var list<string>
     */
    public const READ_ONLY_METHODS = [
        'imconnector.status',
        'imopenlines.config.get',
        'imopenlines.crm.chat.get',
        'imopenlines.dialog.get',
    ];

    /**
     * Every mutating Open Lines method used by the application must be listed
     * here. Unknown imconnector/imopenlines methods fail closed in the client.
     *
     * @var array<string, list<string>>
     */
    public const MUTATING_METHOD_SCOPES = [
        'imconnector.connector.data.set' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_CONNECTOR_REGISTRATION,
        ],
        'imconnector.register' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_CONNECTOR_REGISTRATION,
        ],
        'imconnector.send.messages' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        ],
        'imconnector.send.status.delivery' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        ],
        'imopenlines.config.add' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_CONNECTOR_REGISTRATION,
        ],
        'imopenlines.config.update' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_CONNECTOR_REGISTRATION,
        ],
        'imopenlines.crm.chat.user.add' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        ],
        'imopenlines.crm.message.add' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        ],
        'imopenlines.operator.another.finish' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        ],
        'imopenlines.session.open' => [
            Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
        ],
    ];

    /**
     * Identity fields that the REST payload itself can and must expose.
     *
     * @var array<string, array{line:bool,connector:bool,user_code:bool,chat_target:bool}>
     */
    public const MUTATING_METHOD_IDENTITIES = [
        'imconnector.connector.data.set' => ['line' => true, 'connector' => true, 'user_code' => false, 'chat_target' => false],
        'imconnector.register' => ['line' => false, 'connector' => true, 'user_code' => false, 'chat_target' => false],
        'imconnector.send.messages' => ['line' => true, 'connector' => true, 'user_code' => false, 'chat_target' => false],
        'imconnector.send.status.delivery' => ['line' => true, 'connector' => true, 'user_code' => false, 'chat_target' => false],
        'imopenlines.config.add' => ['line' => false, 'connector' => false, 'user_code' => false, 'chat_target' => false],
        'imopenlines.config.update' => ['line' => true, 'connector' => false, 'user_code' => false, 'chat_target' => false],
        'imopenlines.crm.chat.user.add' => ['line' => false, 'connector' => false, 'user_code' => false, 'chat_target' => true],
        'imopenlines.crm.message.add' => ['line' => false, 'connector' => false, 'user_code' => false, 'chat_target' => true],
        'imopenlines.operator.another.finish' => ['line' => false, 'connector' => false, 'user_code' => false, 'chat_target' => true],
        'imopenlines.session.open' => ['line' => false, 'connector' => false, 'user_code' => true, 'chat_target' => false],
    ];

    public static function requiresAuthority(string $method): bool
    {
        return array_key_exists(self::normalize($method), self::MUTATING_METHOD_SCOPES);
    }

    public static function isReadOnly(string $method): bool
    {
        return in_array(self::normalize($method), self::READ_ONLY_METHODS, true);
    }

    public static function isOpenLinesMethod(string $method): bool
    {
        $method = self::normalize($method);

        return str_starts_with($method, 'imconnector.')
            || str_starts_with($method, 'imopenlines.');
    }

    /**
     * @return list<string>
     */
    public static function allowedScopes(string $method): array
    {
        return self::MUTATING_METHOD_SCOPES[self::normalize($method)] ?? [];
    }

    /**
     * @return array{line:bool,connector:bool,user_code:bool,chat_target:bool}
     */
    public static function requiredPayloadIdentity(string $method): array
    {
        return self::MUTATING_METHOD_IDENTITIES[self::normalize($method)]
            ?? ['line' => false, 'connector' => false, 'user_code' => false, 'chat_target' => false];
    }

    public static function assertClassified(string $method): void
    {
        if (! self::isOpenLinesMethod($method)
            || self::isReadOnly($method)
            || self::requiresAuthority($method)
        ) {
            return;
        }

        throw new Bitrix24OpenLineMutationAuthorityException(
            'openlines_rest_method_unclassified',
            sprintf(
                'Open Lines REST-метод %s не классифицирован; внешний вызов заблокирован.',
                self::normalize($method),
            ),
        );
    }

    private static function normalize(string $method): string
    {
        return mb_strtolower(trim($method));
    }
}
