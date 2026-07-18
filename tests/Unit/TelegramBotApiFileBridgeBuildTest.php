<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TelegramBotApiFileBridgeBuildTest extends TestCase
{
    public function test_writer_and_bridge_share_only_the_dedicated_media_group(): void
    {
        $dockerfile = $this->contents('.devcontainer/telegram-bot-api-file-bridge/Dockerfile');
        $authEntrypoint = $this->contents('.devcontainer/telegram-bot-api-file-bridge/40-create-basic-auth.sh');
        $repairEntrypoint = $this->contents('.devcontainer/telegram-bot-api-file-bridge/repair-existing-media-permissions.sh');
        $nginx = $this->contents('.devcontainer/telegram-bot-api-file-bridge/default.conf');
        $compose = $this->contents('docker-compose.yml');

        $this->assertStringContainsString('ARG TELEGRAM_MEDIA_GID=20000', $dockerfile);
        $this->assertStringContainsString('addgroup -S -g "${TELEGRAM_MEDIA_GID}" telegram-media', $dockerfile);
        $this->assertStringContainsString('user  nginx telegram-media;', $dockerfile);
        $this->assertStringContainsString('repair-existing-media-permissions.sh', $dockerfile);
        $this->assertStringContainsString('chown root:telegram-media /etc/nginx/.telegram-bot-api-files.htpasswd', $authEntrypoint);
        $this->assertStringNotContainsString('chown root:nginx /etc/nginx/.telegram-bot-api-files.htpasswd', $authEntrypoint);
        $this->assertStringContainsString('find "${root}" -xdev -type d -exec sh -eu -c', $repairEntrypoint);
        $this->assertStringContainsString('chmod g=x "${path}"', $repairEntrypoint);
        $this->assertStringContainsString('chmod g=r "${path}"', $repairEntrypoint);
        $this->assertStringContainsString('stat -c %g', $repairEntrypoint);
        $this->assertStringContainsString('stat -c %a', $repairEntrypoint);

        foreach ($this->mediaDirectories() as $directory) {
            $this->assertStringContainsString("-path '*/{$directory}/*'", $repairEntrypoint);
        }

        $this->assertStringNotContainsString("-path '*/audio/*'", $repairEntrypoint);

        foreach (['secret', 'secret_thumbnails', 'passport', 'temp'] as $privateDirectory) {
            $this->assertStringNotContainsString("-path '*/{$privateDirectory}/*'", $repairEntrypoint);
        }

        $this->assertStringContainsString(
            '(?:animations|documents|music|photos|profile_photos|stickers|thumbnails|video_notes|videos|voice)',
            $nginx,
        );
        $this->assertStringContainsString('location ~ ^/files/(?<telegram_media_path>', $nginx);
        $this->assertStringContainsString('alias /var/lib/telegram-bot-api/$telegram_media_path;', $nginx);
        $this->assertMatchesRegularExpression(
            '/location \/files\/ \{\R\s+return 404;\R\s+\}/',
            $nginx,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/location \/files\/ \{.*?alias/s',
            $nginx,
        );

        $this->assertMatchesRegularExpression(
            '/telegram-bot-api:\R.*?user: "0:20000"/s',
            $compose,
        );
        $this->assertMatchesRegularExpression(
            '/telegram-bot-api-file-bridge:\R.*?TELEGRAM_MEDIA_GID: 20000/s',
            $compose,
        );
        $this->assertMatchesRegularExpression(
            '/telegram-bot-api-file-bridge:\R.*?- \.\/storage\/app\/telegram-bot-api:\/var\/lib\/telegram-bot-api:ro/s',
            $compose,
        );
        $this->assertMatchesRegularExpression(
            '/telegram-bot-api-media-permissions:\R.*?entrypoint:\R.*?repair-existing-media-permissions/s',
            $compose,
        );
        $this->assertMatchesRegularExpression(
            '/telegram-bot-api-media-permissions:\R.*?depends_on:\R.*?telegram-bot-api:\R.*?service_healthy/s',
            $compose,
        );
        $this->assertMatchesRegularExpression(
            '/telegram-bot-api-file-bridge:\R.*?depends_on:\R.*?telegram-bot-api-media-permissions:\R.*?service_completed_successfully/s',
            $compose,
        );
    }

    public function test_runtime_contract_covers_private_state_and_authenticated_media_reads(): void
    {
        $verifier = $this->contents('.devcontainer/telegram-bot-api-file-bridge/verify-file-access-contract.sh');
        $workflow = $this->contents('.github/workflows/telegram-file-bridge-contract.yml');

        $this->assertStringContainsString('chmod 0750', $verifier);
        $this->assertStringContainsString('chmod 0640', $verifier);
        $this->assertStringContainsString('chmod 0600', $verifier);
        $this->assertStringContainsString('/music/file.mp3', $verifier);
        $this->assertStringContainsString('/audio/file.mp3', $verifier);
        $this->assertStringContainsString('/db.sqlite', $verifier);
        $this->assertStringContainsString('/tmp/partial.bin', $verifier);
        $this->assertStringContainsString('/td.binlog', $verifier);
        $this->assertStringContainsString('--path-as-is', $verifier);
        $this->assertStringContainsString('/videos/%2e%2e/td.binlog', $verifier);
        $this->assertStringContainsString('0:20000:640', $verifier);
        $this->assertStringContainsString('0:20000:710', $verifier);
        $this->assertStringContainsString('Range: bytes=0-0', $verifier);
        $this->assertStringContainsString('assert_status 401', $verifier);
        $this->assertStringContainsString('assert_status 403', $verifier);
        $this->assertStringContainsString('assert_status 404', $verifier);
        $this->assertStringContainsString('repair-existing-media-permissions', $verifier);
        $this->assertStringContainsString('--user 0:20000', $verifier);
        $this->assertStringContainsString('0:0:644', $verifier);
        $this->assertStringContainsString('Expected repair to fail when chgrp fails.', $verifier);
        $this->assertStringContainsString('Expected repair post-verification to reject a no-op chgrp.', $verifier);
        $this->assertStringContainsString('/fixture/fake-bin/chgrp', $verifier);
        $this->assertStringContainsString('telegram-media', $this->contents('.devcontainer/telegram-bot-api-file-bridge/Dockerfile'));
        $this->assertStringContainsString(
            'cp .env.telegram-bot-api.example .env.telegram-bot-api',
            $workflow,
        );
        $this->assertStringContainsString(
            'cp .env.telegram-bot-api-file-bridge.example .env.telegram-bot-api-file-bridge',
            $workflow,
        );
    }

    /**
     * @return list<string>
     */
    private function mediaDirectories(): array
    {
        return [
            'animations',
            'documents',
            'music',
            'photos',
            'profile_photos',
            'stickers',
            'thumbnails',
            'video_notes',
            'videos',
            'voice',
        ];
    }

    private function contents(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath);

        $this->assertNotFalse($contents, "Unable to read {$relativePath}.");

        return $contents;
    }
}
