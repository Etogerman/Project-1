<?php

namespace App\Console\Commands;

use App\Models\Bitrix24Profile;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use App\Services\Bitrix24\DoctorBitrix24OpenLinesRouteRegistryAction;
use Illuminate\Console\Command;

class Bitrix24DoctorOpenLinesRouteRegistryCommand extends Command
{
    protected $signature = 'bitrix24:openlines-routes:doctor
        {--profile= : Bitrix24 profile_key}
        {--portal= : Bitrix24 portal domain}';

    protected $description = 'Compare local Open Lines routes with the Bitrix managed-code route registry.';

    public function __construct(
        private readonly DoctorBitrix24OpenLinesRouteRegistryAction $doctorAction,
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
            $result = $this->doctorAction->handle($profile);
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->error('OpenLines registry doctor failed: '.$exception->errorCode);

            return self::FAILURE;
        }

        $this->table(
            ['Profile', 'Portal', 'Status', 'Owners', 'Diffs'],
            [[
                $profile->profile_key,
                $profile->portal_domain,
                $result['status'],
                (string) $result['checked_owners'],
                (string) $result['diff_count'],
            ]],
        );

        foreach ($result['diffs'] as $diff) {
            $this->warn($diff);
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return $result['status'] === Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED
            ? self::SUCCESS
            : self::FAILURE;
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
