<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestingEnvironment;

class TestingEnvironmentTest extends TestCase
{
    public function test_existing_testing_environment_is_loaded_instead_of_base_environment(): void
    {
        $projectRoot = $this->makeTemporaryProjectRoot();
        $key = $this->uniqueEnvironmentKey();
        file_put_contents($projectRoot.'/.env', $key."=base\n");
        file_put_contents($projectRoot.'/.env.testing', $key."=testing\n");

        try {
            TestingEnvironment::load($projectRoot, 'testing');

            $this->assertSame('testing', Env::get($key));
        } finally {
            Env::getRepository()->clear($key);
            unlink($projectRoot.'/.env.testing');
            unlink($projectRoot.'/.env');
            rmdir($projectRoot);
        }
    }

    public function test_base_environment_adds_values_missing_from_testing_example(): void
    {
        $projectRoot = $this->makeTemporaryProjectRoot();
        $exampleKey = $this->uniqueEnvironmentKey();
        $baseOnlyKey = $this->uniqueEnvironmentKey();
        file_put_contents($projectRoot.'/.env.testing.example', $exampleKey."=example\n");
        file_put_contents(
            $projectRoot.'/.env',
            $exampleKey."=base\n".$baseOnlyKey."=runtime-database-url\n",
        );

        try {
            TestingEnvironment::load($projectRoot, 'testing');

            $this->assertSame('example', Env::get($exampleKey));
            $this->assertSame('runtime-database-url', Env::get($baseOnlyKey));
        } finally {
            Env::getRepository()->clear($exampleKey);
            Env::getRepository()->clear($baseOnlyKey);
            unlink($projectRoot.'/.env.testing.example');
            unlink($projectRoot.'/.env');
            rmdir($projectRoot);
        }
    }

    public function test_value_uses_laravel_special_value_normalization(): void
    {
        $key = $this->uniqueEnvironmentKey();
        Env::getRepository()->set($key, 'null');

        try {
            $this->assertNull(TestingEnvironment::value($key));
        } finally {
            Env::getRepository()->clear($key);
        }
    }

    private function makeTemporaryProjectRoot(): string
    {
        $projectRoot = sys_get_temp_dir().'/testing-environment-'.bin2hex(random_bytes(8));
        mkdir($projectRoot, 0777, true);

        return $projectRoot;
    }

    private function uniqueEnvironmentKey(): string
    {
        return 'AB_TEST_ENV_'.strtoupper(bin2hex(random_bytes(8)));
    }
}
