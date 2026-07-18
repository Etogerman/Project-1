<?php

namespace Tests\Unit;

use App\Services\Messages\PruneInboundMediaTemporaryFilesAction;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PruneInboundMediaTemporaryFilesActionTest extends TestCase
{
    public function test_stale_unlocked_temporary_file_is_deleted(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory.DIRECTORY_SEPARATOR.'inbound-media-orphan';
        File::put($path, 'orphan');
        touch($path, time() - 181);

        try {
            $stats = app(PruneInboundMediaTemporaryFilesAction::class)->handle();

            $this->assertSame(1, $stats['inspected']);
            $this->assertSame(1, $stats['deleted']);
            $this->assertFileDoesNotExist($path);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_active_locked_temporary_file_is_preserved(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory.DIRECTORY_SEPARATOR.'inbound-media-active';
        $stream = fopen($path, 'w+b');

        $this->assertIsResource($stream);
        $this->assertTrue(flock($stream, LOCK_EX | LOCK_NB));
        touch($path, time() - 181);

        try {
            $stats = app(PruneInboundMediaTemporaryFilesAction::class)->handle();

            $this->assertSame(1, $stats['inspected']);
            $this->assertSame(0, $stats['deleted']);
            $this->assertSame(1, $stats['skipped']);
            $this->assertFileExists($path);

            fclose($stream);
            $stream = null;

            $afterUnlock = app(PruneInboundMediaTemporaryFilesAction::class)->handle();

            $this->assertSame(1, $afterUnlock['deleted']);
            $this->assertFileDoesNotExist($path);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            File::deleteDirectory($directory);
        }
    }

    public function test_fresh_unlocked_temporary_file_is_preserved(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory.DIRECTORY_SEPARATOR.'inbound-media-fresh';
        File::put($path, 'fresh');

        try {
            $stats = app(PruneInboundMediaTemporaryFilesAction::class)->handle();

            $this->assertSame(1, $stats['inspected']);
            $this->assertSame(0, $stats['deleted']);
            $this->assertSame(1, $stats['skipped']);
            $this->assertFileExists($path);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_oldest_temporary_file_is_processed_first(): void
    {
        $directory = $this->temporaryDirectory();
        $freshPath = $directory.DIRECTORY_SEPARATOR.'inbound-media-aaa-fresh';
        $oldestPath = $directory.DIRECTORY_SEPARATOR.'inbound-media-zzz-oldest';
        File::put($freshPath, 'fresh');
        File::put($oldestPath, 'oldest');
        touch($oldestPath, time() - 300);

        try {
            $stats = app(PruneInboundMediaTemporaryFilesAction::class)->handle(1);

            $this->assertSame(1, $stats['inspected']);
            $this->assertSame(1, $stats['deleted']);
            $this->assertFileDoesNotExist($oldestPath);
            $this->assertFileExists($freshPath);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_stale_symlink_is_ignored(): void
    {
        $directory = $this->temporaryDirectory();
        $targetPath = storage_path('framework/testing/inbound-media-prune-target-'.uniqid('', true));
        $linkPath = $directory.DIRECTORY_SEPARATOR.'inbound-media-link';
        File::put($targetPath, 'target');
        symlink($targetPath, $linkPath);
        touch($linkPath, time() - 300);

        try {
            $stats = app(PruneInboundMediaTemporaryFilesAction::class)->handle();

            $this->assertSame(1, $stats['inspected']);
            $this->assertSame(0, $stats['deleted']);
            $this->assertSame(1, $stats['skipped']);
            $this->assertTrue(is_link($linkPath));
            $this->assertFileExists($targetPath);
        } finally {
            File::deleteDirectory($directory);
            File::delete($targetPath);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path('framework/testing/inbound-media-prune-'.uniqid('', true));
        File::ensureDirectoryExists($directory);
        config([
            'inbound_media.temporary_directory' => $directory,
            'inbound_media.attempt_deadline_seconds' => 60,
            'inbound_media.lease_stale_seconds' => 60,
        ]);

        return $directory;
    }
}
