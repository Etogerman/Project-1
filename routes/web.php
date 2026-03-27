<?php

use App\Http\Controllers\BotWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::post('/webhooks/telegram/{channel}', [BotWebhookController::class, 'telegram'])
    ->name('webhooks.telegram.handle');

Route::post('/webhooks/max/{channel}', [BotWebhookController::class, 'max'])
    ->name('webhooks.max.handle');
