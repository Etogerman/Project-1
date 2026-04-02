<?php

use App\Http\Controllers\Bitrix24CallbackController;
use App\Http\Controllers\Bitrix24ProbeController;
use App\Http\Controllers\BotWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::post('/webhooks/telegram/{channel}', [BotWebhookController::class, 'telegram'])
    ->name('webhooks.telegram.handle');

Route::post('/webhooks/max/{channel}', [BotWebhookController::class, 'max'])
    ->name('webhooks.max.handle');

Route::match(['GET', 'POST'], '/callbacks/bitrix24/install', [Bitrix24CallbackController::class, 'install'])
    ->middleware('throttle:bitrix24-install')
    ->name('callbacks.bitrix24.install');

Route::match(['GET', 'POST'], '/callbacks/bitrix24/events', [Bitrix24CallbackController::class, 'events'])
    ->middleware('throttle:bitrix24-events')
    ->name('callbacks.bitrix24.events');

Route::match(['GET', 'POST'], '/callbacks/bitrix24/openlines', [Bitrix24CallbackController::class, 'openlines'])
    ->middleware('throttle:bitrix24-openlines')
    ->name('callbacks.bitrix24.openlines');

Route::match(['GET', 'POST'], '/callbacks/bitrix24/probe', Bitrix24ProbeController::class)
    ->name('callbacks.bitrix24.probe');
