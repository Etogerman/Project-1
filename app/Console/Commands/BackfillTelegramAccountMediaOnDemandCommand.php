<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use App\Services\TelegramAccount\ClaimTelegramAccountMediaDownloadAction;
use App\Services\TelegramAccount\TelegramAccountMediaDownloadPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillTelegramAccountMediaOnDemandCommand extends Command
{
    protected $signature = 'telegram-account-media:backfill-on-demand
        {--channel=* : Ограничить преобразование идентификаторами каналов}
        {--apply : Сохранить изменения вместо dry-run}';

    protected $description = 'Преобразовать старые ошибки file_too_large в доступные для ручной загрузки файлы';

    public function handle(): int
    {
        $channelIds = collect($this->option('channel'))
            ->filter(static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0)
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $query = $this->eligibleAttachmentsQuery($channelIds);
        $matched = (clone $query)->count();

        $this->table(
            ['Режим', 'Найдено', 'Каналы'],
            [[
                $this->option('apply') ? 'запись' : 'dry-run',
                $matched,
                $channelIds === [] ? 'все' : implode(', ', $channelIds),
            ]],
        );

        if (! $this->option('apply') || $matched === 0) {
            $this->info($matched === 0
                ? 'Подходящих вложений не найдено.'
                : 'Dry-run завершён. Для сохранения изменений добавьте --apply.');

            return self::SUCCESS;
        }

        $updated = $query->update([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'safe_error_code' => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
            'safe_error_message' => null,
            'local_disk' => null,
            'local_path' => null,
            'updated_at' => now(),
        ]);

        $this->info("Преобразовано вложений: {$updated}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $channelIds
     */
    private function eligibleAttachmentsQuery(array $channelIds): Builder
    {
        return MessageAttachment::query()
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED)
            ->where('safe_error_code', ClaimTelegramAccountMediaDownloadAction::ERROR_FILE_TOO_LARGE)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('provider_file_id')
                    ->where('provider_file_id', '!=', '')
                    ->orWhere(function (Builder $referenceQuery): void {
                        $referenceQuery
                            ->whereNotNull('provider_file_reference')
                            ->where('provider_file_reference', '!=', '');
                    });
            })
            ->when(
                $channelIds !== [],
                static fn (Builder $query): Builder => $query->whereIn('channel_id', $channelIds),
            );
    }
}
