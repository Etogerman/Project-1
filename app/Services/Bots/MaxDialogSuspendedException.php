<?php

namespace App\Services\Bots;

use Illuminate\Http\Client\Response;
use RuntimeException;

class MaxDialogSuspendedException extends RuntimeException
{
    private const USER_MESSAGE = 'Клиент заблокировал бота в MAX. Новые сообщения в этот диалог сейчас отправлять нельзя.';

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    public function __construct(
        public readonly array $responsePayload = [],
    ) {
        parent::__construct(self::USER_MESSAGE);
    }

    public static function fromResponse(Response $response): self
    {
        $payload = $response->json();

        return new self(is_array($payload) ? $payload : [
            'body' => $response->body(),
            'status' => $response->status(),
        ]);
    }
}
