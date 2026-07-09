<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Env;

final class TestingEnvironment
{
    public static function value(string $key): ?string
    {
        $value = Env::get($key);

        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return trim((string) $value, " \t\n\r\0\x0B\"'");
    }

    public static function load(string $projectRoot, ?string $appEnv): void
    {
        if ($appEnv === 'testing' && ! is_file($projectRoot.'/.env.testing')) {
            if (is_file($projectRoot.'/.env.testing.example')) {
                Dotenv::createImmutable($projectRoot, '.env.testing.example')->safeLoad();
            }

            if (is_file($projectRoot.'/.env')) {
                self::loadWithLaravelRepository($projectRoot, '.env');
            }

            return;
        }

        $environmentFile = self::environmentFile($projectRoot, $appEnv);

        if ($environmentFile !== null) {
            self::loadWithLaravelRepository($projectRoot, $environmentFile);
        }
    }

    private static function environmentFile(string $projectRoot, ?string $appEnv): ?string
    {
        $specificEnvironmentFile = $appEnv !== null ? '.env.'.$appEnv : null;

        if ($specificEnvironmentFile !== null && is_file($projectRoot.'/'.$specificEnvironmentFile)) {
            return $specificEnvironmentFile;
        }

        return is_file($projectRoot.'/.env') ? '.env' : null;
    }

    private static function loadWithLaravelRepository(string $projectRoot, string $environmentFile): void
    {
        Dotenv::create(Env::getRepository(), $projectRoot, $environmentFile)->safeLoad();
    }
}
