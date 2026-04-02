<?php

namespace App\Providers;

use App\Models\AutoReplyRule;
use App\Models\Bitrix24Connection;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\User;
use App\Policies\AutoReplyRulePolicy;
use App\Policies\Bitrix24ConnectionPolicy;
use App\Policies\ChannelPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DialogPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Dialog::class, DialogPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AutoReplyRule::class, AutoReplyRulePolicy::class);
        Gate::policy(Bitrix24Connection::class, Bitrix24ConnectionPolicy::class);

        RateLimiter::for('bitrix24-install', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('bitrix24.rate_limits.install.max_per_minute', 30)))
                ->by($this->resolveBitrix24CallbackRateLimitKey($request));
        });

        RateLimiter::for('bitrix24-events', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('bitrix24.rate_limits.events.max_per_minute', 300)))
                ->by($this->resolveBitrix24CallbackRateLimitKey($request));
        });

        RateLimiter::for('bitrix24-openlines', function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('bitrix24.rate_limits.openlines.max_per_minute', 300)))
                ->by($this->resolveBitrix24CallbackRateLimitKey($request));
        });
    }

    private function resolveBitrix24CallbackRateLimitKey(Request $request): string
    {
        $memberId = $this->normalizeRateLimitValue($request->input('auth.member_id'));
        $applicationToken = $this->normalizeRateLimitValue($request->input('auth.application_token'));

        if ($memberId !== null && $applicationToken !== null) {
            return $memberId.':'.$applicationToken;
        }

        $domain = $this->normalizeRateLimitValue($request->input('auth.domain'));

        if ($domain !== null) {
            return $domain;
        }

        return 'ip:'.($request->ip() ?: 'unknown');
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
