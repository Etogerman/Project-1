<?php

namespace Tests\Feature;

use App\Support\AppVersion;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    public function test_normalize_shortens_commit_hashes(): void
    {
        $this->assertSame('abcdef1', AppVersion::normalize('abcdef1234567890abcdef1234567890abcdef12'));
    }

    public function test_display_prefixes_commit_hashes_with_rev(): void
    {
        $this->assertSame('rev abcdef1', AppVersion::displayFromVersion('abcdef1234567890abcdef1234567890abcdef12'));
    }

    public function test_resolve_from_git_directory_reads_head_reference(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'app-version-'.uniqid();

        mkdir($directory, 0777, true);
        mkdir($directory.DIRECTORY_SEPARATOR.'refs'.DIRECTORY_SEPARATOR.'heads', 0777, true);

        file_put_contents($directory.DIRECTORY_SEPARATOR.'HEAD', "ref: refs/heads/main\n");
        file_put_contents($directory.DIRECTORY_SEPARATOR.'refs'.DIRECTORY_SEPARATOR.'heads'.DIRECTORY_SEPARATOR.'main', "f8cb57f30da90a70584776922e4d8b64913d976e\n");

        $this->assertSame('f8cb57f', AppVersion::resolveFromGitDirectory($directory));
    }

    public function test_resolve_from_file_reads_plain_version_label(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'app-version-file-'.uniqid();

        file_put_contents($path, "2026.03.27.2\n");

        $this->assertSame('2026.03.27.2', AppVersion::resolveFromFile($path));
    }

    public function test_environment_indicator_exposes_normalized_revision_marker(): void
    {
        $this->withTemporaryAppVersion('ABCDEF1234567890ABCDEF1234567890ABCDEF12', function (): void {
            $html = view('filament.components.environment-indicator')->render();

            $this->assertStringContainsString('data-role="environment-indicator"', $html);
            $this->assertStringContainsString('data-app-version="abcdef1"', $html);
            $this->assertStringContainsString('rev abcdef1', $html);
        });
    }

    private function withTemporaryAppVersion(string $value, callable $callback): void
    {
        $previousEnvValue = $_ENV['APP_VERSION'] ?? null;
        $previousServerValue = $_SERVER['APP_VERSION'] ?? null;
        $previousProcessValue = getenv('APP_VERSION');

        $_ENV['APP_VERSION'] = $value;
        $_SERVER['APP_VERSION'] = $value;
        putenv('APP_VERSION='.$value);

        try {
            $callback();
        } finally {
            $this->restoreEnvironmentValue('APP_VERSION', $previousEnvValue, $_ENV);
            $this->restoreEnvironmentValue('APP_VERSION', $previousServerValue, $_SERVER);

            if ($previousProcessValue === false) {
                putenv('APP_VERSION');
            } else {
                putenv('APP_VERSION='.$previousProcessValue);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function restoreEnvironmentValue(string $key, ?string $value, array &$target): void
    {
        if ($value === null) {
            unset($target[$key]);

            return;
        }

        $target[$key] = $value;
    }
}
