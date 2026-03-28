<?php

namespace App\Providers;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Policies\AutoReplyRulePolicy;
use App\Policies\ChannelPolicy;
use App\Policies\ContactPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AutoReplyRule::class, AutoReplyRulePolicy::class);
    }
}
