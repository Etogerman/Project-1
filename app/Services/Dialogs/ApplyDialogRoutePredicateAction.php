<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Builder;

class ApplyDialogRoutePredicateAction
{
    public function __construct(
        private readonly DialogRoutePredicate $dialogRoutePredicate,
    ) {}

    public function applyReady(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('bot_subscription_status')
                    ->orWhere('bot_subscription_status', '!=', \App\Models\Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER);
            })
            ->whereHas('channel', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
                ->where('bot_token_present', true)
                ->whereIn('platform', $this->dialogRoutePredicate->supportedPlatforms())
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $telegramQuery): void {
                            $this->applyFreshTelegramConnection($telegramQuery);
                        })
                        ->orWhere('platform', '!=', Channel::PLATFORM_TELEGRAM);
                }))
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $telegramQuery): void {
                        $telegramQuery
                            ->whereHas('channel', fn (Builder $query): Builder => $query->where('platform', Channel::PLATFORM_TELEGRAM))
                            ->whereNotNull('external_chat_id')
                            ->where('external_chat_id', '!=', '');
                    })
                    ->orWhere(function (Builder $maxQuery): void {
                        $maxQuery
                            ->whereHas('channel', fn (Builder $query): Builder => $query->where('platform', Channel::PLATFORM_MAX))
                            ->where(function (Builder $routeQuery): void {
                                $routeQuery
                                    ->where(function (Builder $chatQuery): void {
                                        $chatQuery
                                            ->whereNotNull('external_chat_id')
                                            ->where('external_chat_id', '!=', '');
                                    })
                                    ->orWhereHas('currentContactIdentity', fn (Builder $query): Builder => $query
                                        ->whereNotNull('external_user_id')
                                        ->where('external_user_id', '!=', ''));
                            });
                    });
            });
    }

    public function applyProblem(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereDoesntHave('channel')
                ->orWhere('bot_subscription_status', \App\Models\Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER)
                ->orWhereHas('channel', function (Builder $query): void {
                    $query
                        ->where('is_active', false)
                        ->orWhere('connection_type', '!=', Channel::CONNECTION_TYPE_BOT)
                        ->orWhere('bot_token_present', false)
                        ->orWhereNotIn('platform', $this->dialogRoutePredicate->supportedPlatforms());
                })
                ->orWhereHas('channel', function (Builder $query): void {
                    $query
                        ->where('platform', Channel::PLATFORM_TELEGRAM)
                        ->where('is_active', true)
                        ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
                        ->where('bot_token_present', true)
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('connection_status')
                                ->orWhere('connection_status', '!=', Channel::CONNECTION_STATUS_CONNECTED)
                                ->orWhereNull('webhook_status')
                                ->orWhere('webhook_status', '!=', Channel::WEBHOOK_STATUS_INSTALLED)
                                ->orWhereNull('connection_checked_at')
                                ->orWhere('connection_checked_at', '<', now()->subMinutes(2));
                        });
                })
                ->orWhere(function (Builder $telegramQuery): void {
                    $telegramQuery
                        ->whereHas('channel', fn (Builder $query): Builder => $query
                            ->where('platform', Channel::PLATFORM_TELEGRAM)
                            ->where('is_active', true)
                            ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
                            ->where('bot_token_present', true))
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('external_chat_id')
                                ->orWhere('external_chat_id', '');
                        });
                })
                ->orWhere(function (Builder $maxQuery): void {
                    $maxQuery
                        ->whereHas('channel', fn (Builder $query): Builder => $query
                            ->where('platform', Channel::PLATFORM_MAX)
                            ->where('is_active', true)
                            ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
                            ->where('bot_token_present', true))
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('external_chat_id')
                                ->orWhere('external_chat_id', '');
                        })
                        ->whereDoesntHave('currentContactIdentity', fn (Builder $query): Builder => $query
                            ->whereNotNull('external_user_id')
                            ->where('external_user_id', '!=', ''));
                });
        });
    }

    private function applyFreshTelegramConnection(Builder $query): void
    {
        $query
            ->where('platform', Channel::PLATFORM_TELEGRAM)
            ->where('connection_status', Channel::CONNECTION_STATUS_CONNECTED)
            ->where('webhook_status', Channel::WEBHOOK_STATUS_INSTALLED)
            ->whereNotNull('connection_checked_at')
            ->where('connection_checked_at', '>=', now()->subMinutes(2));
    }
}
