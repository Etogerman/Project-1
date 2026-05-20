<?php

namespace App\Services\Bitrix24;

use App\Models\Message;

class FormatV3ScenarioButtonsForBitrixAction
{
    public function append(Message $message, string $text): string
    {
        $summary = $this->handle($message);

        if ($summary === '') {
            return $text;
        }

        return $text."\n\n".$summary;
    }

    public function handle(Message $message): string
    {
        $rows = data_get($message->raw_payload, 'v3.buttons.rows');

        if (! is_array($rows)) {
            return '';
        }

        $lines = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $button) {
                if (! is_array($button)) {
                    continue;
                }

                $line = $this->buttonSummaryLine($button, count($lines) + 1);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        if ($lines === []) {
            return '';
        }

        return "Кнопки:\n".implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $button
     */
    private function buttonSummaryLine(array $button, int $number): ?string
    {
        $label = $this->nullableString($button['text'] ?? null);

        if ($label === null) {
            return null;
        }

        $suffix = match ($button['type'] ?? null) {
            'request_phone' => ' (запрос телефона)',
            'link' => ' (ссылка)',
            default => '',
        };

        return $number.'. '.$label.$suffix;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
