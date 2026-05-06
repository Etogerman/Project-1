<?php

namespace App\Console\Commands;

use App\Services\Bitrix24\Bitrix24DevProfileBootstrapException;
use App\Services\Bitrix24\BootstrapBitrix24DevProfileAction;
use Illuminate\Console\Command;

class Bitrix24DevProfileBootstrapCommand extends Command
{
    protected $signature = 'bitrix24:dev-profile-bootstrap
        {profile_key : Canonical dev-* profile key, for example dev-ivan-main}
        {callback_base_url : Current callback base URL, usually the active tunnel URL}
        {--client-id= : Bitrix app client_id for this profile}
        {--application-code= : Bitrix app application_code for this profile}
        {--telegram-line-id= : Deprecated; configure Telegram LINE_ID on concrete channel routes in admin}
        {--max-line-id= : Deprecated; configure MAX LINE_ID on concrete channel routes in admin}
        {--display-name= : Optional operator-facing display name}
        {--portal-domain= : Portal domain override; defaults to bitrix24.portal_domain}';

    protected $description = 'Create or update a full_live dev-* Bitrix24 profile and print manual Bitrix setup instructions.';

    public function __construct(
        private readonly BootstrapBitrix24DevProfileAction $bootstrapBitrix24DevProfileAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->bootstrapBitrix24DevProfileAction->handle(
                profileKey: (string) $this->argument('profile_key'),
                callbackBaseUrl: (string) $this->argument('callback_base_url'),
                clientId: $this->option('client-id'),
                applicationCode: $this->option('application-code'),
                telegramLineId: $this->option('telegram-line-id'),
                maxLineId: $this->option('max-line-id'),
                displayName: $this->option('display-name'),
                portalDomain: $this->option('portal-domain'),
            );
        } catch (Bitrix24DevProfileBootstrapException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->line('Bitrix24 dev-profile bootstrap completed.');
        $this->line($result->created
            ? 'Profile action: created.'
            : 'Profile action: updated.');

        $this->newLine();
        $this->table(['Field', 'Value'], $result->profileRows());

        $this->newLine();
        $this->table(['Callback', 'Value'], $result->callbackRows());

        $this->newLine();
        $this->table(['Item', 'Required', 'Status', 'Value', 'Notes'], $result->checkTableRows());

        $this->newLine();
        $this->info('Что сделать в Bitrix:');

        foreach ($result->instructionSteps as $index => $step) {
            $this->line(sprintf('%d. %s', $index + 1, $step));
        }

        if ($result->hasBlockingIssues()) {
            $this->newLine();
            $this->warn(
                'Dev-profile сохранён, но full_live setup ещё не готов. Закройте blocking items и повторно запустите команду.',
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Dev-profile готов к full_live handoff и verify-контуру.');

        return self::SUCCESS;
    }
}
