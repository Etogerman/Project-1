<?php

namespace App\Providers;

use App\Listeners\RecordAdminUserLogin;
use App\Models\AiProcessor;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\Bitrix24Connection;
use App\Models\Channel;
use App\Models\ChannelConnectionType;
use App\Models\Contact;
use App\Models\DataDictionaryEntry;
use App\Models\Dialog;
use App\Models\DialogStage;
use App\Models\FieldDictionaryField;
use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use App\Models\Scenario;
use App\Models\User;
use App\Policies\AiProcessorPolicy;
use App\Policies\AutoReplyCategoryPolicy;
use App\Policies\AutoReplyRulePolicy;
use App\Policies\Bitrix24ConnectionPolicy;
use App\Policies\ChannelConnectionTypePolicy;
use App\Policies\ChannelPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DataDictionaryEntryPolicy;
use App\Policies\DialogPolicy;
use App\Policies\DialogStagePolicy;
use App\Policies\FieldDictionaryFieldPolicy;
use App\Policies\GeoAliasPolicy;
use App\Policies\GeoCityPolicy;
use App\Policies\GeoCountryPolicy;
use App\Policies\GeoRegionPolicy;
use App\Policies\ScenarioPolicy;
use App\Policies\UserPolicy;
use App\Services\Dialogs\DialogStageCatalog;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DialogStageCatalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(ChannelConnectionType::class, ChannelConnectionTypePolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(DataDictionaryEntry::class, DataDictionaryEntryPolicy::class);
        Gate::policy(Dialog::class, DialogPolicy::class);
        Gate::policy(DialogStage::class, DialogStagePolicy::class);
        Gate::policy(FieldDictionaryField::class, FieldDictionaryFieldPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Scenario::class, ScenarioPolicy::class);
        Gate::policy(AutoReplyRule::class, AutoReplyRulePolicy::class);
        Gate::policy(AutoReplyCategory::class, AutoReplyCategoryPolicy::class);
        Gate::policy(AiProcessor::class, AiProcessorPolicy::class);
        Gate::policy(Bitrix24Connection::class, Bitrix24ConnectionPolicy::class);
        Gate::policy(GeoCountry::class, GeoCountryPolicy::class);
        Gate::policy(GeoRegion::class, GeoRegionPolicy::class);
        Gate::policy(GeoCity::class, GeoCityPolicy::class);
        Gate::policy(GeoAlias::class, GeoAliasPolicy::class);

        Event::listen(Login::class, RecordAdminUserLogin::class);

        RateLimiter::for('bitrix24-install', function (Request $request): array {
            return $this->resolveBitrix24CallbackRateLimits(
                $request,
                max(1, (int) config('bitrix24.rate_limits.install.max_per_minute', 30)),
            );
        });

        RateLimiter::for('bitrix24-events', function (Request $request): array {
            return $this->resolveBitrix24CallbackRateLimits(
                $request,
                max(1, (int) config('bitrix24.rate_limits.events.max_per_minute', 300)),
            );
        });

        RateLimiter::for('bitrix24-openlines', function (Request $request): array {
            return $this->resolveBitrix24CallbackRateLimits(
                $request,
                max(1, (int) config('bitrix24.rate_limits.openlines.max_per_minute', 300)),
            );
        });

        RateLimiter::for('telegram-account-gateway', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('bots.telegram_account.gateway_rate_limit_per_minute', 120)))
                ->by($this->resolveTelegramAccountGatewayRateLimitKey($request));
        });

        RateLimiter::for('telegram-account-media-upload', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('bots.telegram_account.gateway_media_upload_rate_limit_per_minute', 600)))
                ->by($this->resolveTelegramAccountGatewayRateLimitKey($request));
        });
    }

    private function resolveTelegramAccountGatewayRateLimitKey(Request $request): string
    {
        $channel = $request->route('channel');
        $channelKey = $channel instanceof Channel
            ? (string) $channel->getKey()
            : (is_scalar($channel) ? (string) $channel : 'unknown-channel');

        return $channelKey.':'.($request->ip() ?: 'unknown');
    }

    private function resolveBitrix24CallbackRateLimitKey(Request $request): string
    {
        $ip = $this->resolveRequestIp($request);
        $memberId = $this->normalizeRateLimitValue($request->input('auth.member_id') ?? $request->input('member_id'));
        $applicationToken = $this->normalizeRateLimitValue(
            $request->input('auth.application_token') ?? $request->input('application_token') ?? $request->input('APP_SID'),
        );

        if ($memberId !== null && $applicationToken !== null) {
            return 'member:'.$memberId.'|token:'.hash('sha256', $applicationToken);
        }

        $domain = $this->normalizeRateLimitValue($request->input('auth.domain') ?? $request->input('DOMAIN'));

        if ($domain !== null) {
            return 'domain:'.mb_strtolower($domain);
        }

        return 'ip:'.$ip.'|unknown';
    }

    /**
     * @return list<Limit>
     */
    private function resolveBitrix24CallbackRateLimits(Request $request, int $maxPerMinute): array
    {
        return [
            Limit::perMinute($maxPerMinute)
                ->by($this->resolveBitrix24CallbackRateLimitKey($request)),
            Limit::perMinute(max(10, $maxPerMinute * 10))
                ->by('ip:'.$this->resolveRequestIp($request)),
        ];
    }

    private function resolveRequestIp(Request $request): string
    {
        return $request->ip() ?: 'unknown';
    }

    private function normalizeRateLimitValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
