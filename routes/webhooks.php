<?php

use App\Http\Controllers\Webhooks\GreenApiWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Registered via bootstrap/app.php as the "api" route file (stateless: no
// session, no CSRF) under the "/webhooks" prefix, so external platforms can
// POST here without a browser session or CSRF token.
Route::post('telegram/{channel:uuid}', [TelegramWebhookController::class, 'handle'])->name('webhooks.telegram');

Route::get('whatsapp/{channel:uuid}', [MetaWebhookController::class, 'verify'])
    ->defaults('type', 'whatsapp')->name('webhooks.whatsapp.verify');
Route::post('whatsapp/{channel:uuid}', [MetaWebhookController::class, 'handle'])
    ->defaults('type', 'whatsapp')->name('webhooks.whatsapp');

Route::get('instagram/{channel:uuid}', [MetaWebhookController::class, 'verify'])
    ->defaults('type', 'instagram')->name('webhooks.instagram.verify');
Route::post('instagram/{channel:uuid}', [MetaWebhookController::class, 'handle'])
    ->defaults('type', 'instagram')->name('webhooks.instagram');

// Green API has no GET handshake (the GET just answers 200) and no signature
// header — the driver checks idInstance in the body instead.
Route::get('greenapi/{channel:uuid}', [GreenApiWebhookController::class, 'verify'])->name('webhooks.greenapi.verify');
Route::post('greenapi/{channel:uuid}', [GreenApiWebhookController::class, 'handle'])->name('webhooks.greenapi');
