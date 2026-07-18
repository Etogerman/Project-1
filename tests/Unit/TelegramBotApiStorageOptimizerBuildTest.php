<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TelegramBotApiStorageOptimizerBuildTest extends TestCase
{
    private const BUILDER_IMAGE = 'debian:bookworm@sha256:9344f8b8992482f80cba753f323adeaf17690076c095ccff6cc9536be98185dc';

    private const RUNTIME_IMAGE = 'debian:bookworm-slim@sha256:7b140f374b289a7c2befc338f42ebe6441b7ea838a042bbd5acbfca6ec875818';

    private const BOT_API_COMMIT = 'adfd7f6a8e990272851777eeb3ae0def4216f161';

    private const TDLIB_COMMIT = 'a9966eb3704a3351568c28013fed67d797c17828';

    private const OPTIMIZER_PATCH_SHA256 = '6ffce6b3a46b67a11fc07a37bcf75bff42267f956c90a337d492c6ef40b8cdfd';

    public function test_image_build_is_pinned_and_verifies_the_optimizer_contract_before_compilation(): void
    {
        $dockerfile = $this->contents('.devcontainer/telegram-bot-api/Dockerfile');
        $verifier = $this->contents('.devcontainer/telegram-bot-api/verify-storage-optimizer-contract.sh');

        $this->assertStringContainsString('FROM '.self::BUILDER_IMAGE.' AS builder', $dockerfile);
        $this->assertStringContainsString('FROM '.self::RUNTIME_IMAGE, $dockerfile);
        $this->assertStringContainsString('ARG TELEGRAM_BOT_API_COMMIT='.self::BOT_API_COMMIT, $dockerfile);
        $this->assertStringContainsString('ARG TELEGRAM_TDLIB_COMMIT='.self::TDLIB_COMMIT, $dockerfile);
        $this->assertStringNotContainsString('ARG TELEGRAM_STORAGE_OPTIMIZER_PATCH_SHA256', $dockerfile);
        $this->assertStringContainsString(self::OPTIMIZER_PATCH_SHA256.'  /tmp/storage-optimizer-cadence.patch" | sha256sum --check -', $dockerfile);
        $this->assertStringContainsString('git -C td apply --check /tmp/storage-optimizer-cadence.patch', $dockerfile);
        $this->assertStringContainsString('bash /tmp/verify-storage-optimizer-contract.sh', $dockerfile);
        $this->assertStringContainsString('io.abrikosoff.telegram-storage-optimizer.patch-sha256="'.self::OPTIMIZER_PATCH_SHA256.'"', $dockerfile);
        $this->assertStringContainsString('io.abrikosoff.telegram-storage-optimizer.cadence="15m+5-60s"', $dockerfile);

        $this->assertStringContainsString('pinned_bot_api_commit="'.self::BOT_API_COMMIT.'"', $verifier);
        $this->assertStringContainsString('pinned_td_commit="'.self::TDLIB_COMMIT.'"', $verifier);
        $this->assertStringContainsString('storage_max_files_size", 100 << 10', $verifier);
        $this->assertStringContainsString('storage_max_time_from_last_access", 60 * 60 * 23', $verifier);
        $this->assertStringContainsString('storage_max_file_count", 40000', $verifier);
        $this->assertStringContainsString('storage_immunity_delay", 60 * 60', $verifier);
        $this->assertStringContainsString('//request->use_file_database_ = false;', $verifier);
        $this->assertStringContainsString('if (!G()->use_file_database()) {', $verifier);
        $this->assertStringContainsString('scan_fs(token_', $verifier);
    }

    public function test_patch_changes_only_the_builtin_gc_schedule_and_not_retention_thresholds(): void
    {
        $patch = $this->contents('.devcontainer/telegram-bot-api/storage-optimizer-cadence.patch');
        $patchPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.devcontainer/telegram-bot-api/storage-optimizer-cadence.patch';
        $numstat = [];
        $exitCode = 0;

        $this->assertSame(self::OPTIMIZER_PATCH_SHA256, hash_file('sha256', $patchPath));

        exec('git apply --numstat '.escapeshellarg($patchPath), $numstat, $exitCode);

        $this->assertSame(0, $exitCode, 'The storage optimizer patch must be syntactically valid.');
        $this->assertSame(["6\t3\ttd/telegram/StorageManager.h"], $numstat);

        preg_match_all('/^diff --git a\/(.+) b\/(.+)$/m', $patch, $changedFiles, PREG_SET_ORDER);

        $this->assertSame([
            [
                'diff --git a/td/telegram/StorageManager.h b/td/telegram/StorageManager.h',
                'td/telegram/StorageManager.h',
                'td/telegram/StorageManager.h',
            ],
        ], $changedFiles);
        $this->assertStringContainsString('+  static constexpr int32 GC_EACH = 60 * 15;', $patch);
        $this->assertStringContainsString('+  static constexpr int32 GC_DELAY = 5;', $patch);
        $this->assertStringContainsString('+  static constexpr int32 GC_RAND_DELAY = 55;', $patch);
        $this->assertStringNotContainsString('FileGcParameters.cpp', $patch);
        $this->assertStringNotContainsString('storage_max_files_size', $patch);
        $this->assertStringNotContainsString('storage_max_time_from_last_access', $patch);
        $this->assertStringNotContainsString('storage_immunity_delay', $patch);
    }

    private function contents(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath);

        $this->assertNotFalse($contents, "Unable to read {$relativePath}.");

        return $contents;
    }
}
