<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use Throwable;

class CheckBitrix24ConnectionAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
    ) {}

    public function handle(Bitrix24Connection $connection): void
    {
        try {
            $response = $this->apiClient->call('profile', [], $connection);
        } catch (Throwable $exception) {
            $this->markFailed($connection, $exception->getMessage());

            throw new Bitrix24AdminOAuthException('Не удалось проверить подключение: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful) {
            $message = $response->errorMessage ?? $response->errorCode ?? 'Bitrix24 отклонил проверку подключения.';
            $this->markFailed($connection, $message);

            throw new Bitrix24AdminOAuthException('Не удалось проверить подключение: '.$message);
        }

        $connection->forceFill([
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'last_error_at' => null,
            'last_error_message' => null,
        ])->save();
    }

    private function markFailed(Bitrix24Connection $connection, string $message): void
    {
        $connection->forceFill([
            'last_error_at' => now(),
            'last_error_message' => $message,
        ])->save();
    }
}
