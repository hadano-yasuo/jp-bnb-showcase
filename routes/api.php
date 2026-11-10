<?php

use App\Http\Controllers\Beds24WebhookController;
use Illuminate\Support\Facades\Route;

// beds24 Booking Webhook 受け口。認証は X-Webhook-Secret ヘッダ（controller 側で検証）。
Route::post('/beds24/webhook/bookings', [Beds24WebhookController::class, 'bookings'])
    ->name('beds24.webhook.bookings');
