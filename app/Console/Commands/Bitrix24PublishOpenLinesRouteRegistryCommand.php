<?php

namespace App\Console\Commands;

use App\Models\Bitrix24Profile;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use App\Services\Bitrix24\PublishBitrix24OpenLinesRouteRegistryAction;
use Illuminate\Console\Command;

class Bitrix24PublishOpenLinesRouteRegistryCommand extends Command
{
    protected $signature = 'bitrix24:openlines-routes:publish
        {--profile= : Bitrix24 profile_key}
        {--portal= : Bitrix24 portal domain}';

    protected $description = 'Publish local Open Lines routes to the Bitrix managed-code route registry.';

    public function __construct(
        private readonly PublishBitrix24OpenLinesRouteRegistryAction $publishAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $profile = $this->resolveProfile();

        if (! $profile instanceof Bitrix24Profile) {
            return self::FAILURE;
        }

        try {
            $result = $this->publishAction->handle($profile);
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->error('OpenLines registry publish failed: '.$exception->errorCode);

            return self::FAILURE;
        }

        $this->info('OpenLines registry published.');
        $this->table(
            ['Profile', 'Portal', 'Owners', 'Routes'],
            [[
                $profile->profile_key,
                $profile->portal_domain,
                (string) $result['published_owners'],
                (string) $result['published_routes'],
            ]],
        );

        foreach ($result['owners'] as $owner) {
            $this->line($owner['owner_profile_key'].': '.$owner['published_routes'].' route(s)');
        }

        return self::SUCCESS;
    }

    private function resolveProfile(): ?Bitrix24Profile
    {
        $query = Bitrix24Profile::query()->orderBy('id');
        $profileKey = trim((string) ($this->option('profile') ?? ''));
        $portalDomain = trim((string) ($this->option('portal') ?? ''));

        if ($profileKey !== '') {
            $query->where('profile_key', $profileKey);
        }

        if ($portalDomain !== '') {
            $query->where('portal_domain', $portalDomain);
        }

        $count = (clone $query)->count();

        if ($count !== 1) {
            $this->error('Укажите --portal и --profile так, чтобы был выбран ровно один Bitrix24-профиль.');

            return null;
        }

        $profile = $query->first();

        if (! $profile instanceof Bitrix24Profile) {
            $this->error('Bitrix24-профиль не найден.');

            return null;
        }

        return $profile;
    }
}
