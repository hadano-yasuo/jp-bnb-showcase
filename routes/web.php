<?php

use App\Http\Controllers\GuestRegistrationController;
use App\Http\Controllers\StripeCheckoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| 管理画面の UI ルートは Filament の AdminPanelProvider が自動登録する。
| 本ファイルでは Filament 以外のアプリ固有ルートだけを定義する。
|
*/

/*
|--------------------------------------------------------------------------
| Guest registration (public, tokenized)
|--------------------------------------------------------------------------
|
| 宿泊者本人がチェックイン前に身分証アップロード・代表者情報の入力をするための
| 公開フォーム。48文字の使い捨てトークンで URL を発行する。
|
*/
Route::get('/register/{token}', [GuestRegistrationController::class, 'show'])
    ->name('guest-registrations.show')
    ->where('token', '[A-Za-z0-9]{48}');

Route::post('/register/{token}', [GuestRegistrationController::class, 'store'])
    ->name('guest-registrations.store')
    ->where('token', '[A-Za-z0-9]{48}');

/*
|--------------------------------------------------------------------------
| Stripe webhook
|--------------------------------------------------------------------------
|
| checkout.session.completed → beds24 への POST /bookings を発火。
| CSRF 除外は bootstrap/app.php で設定済み。
|
*/
Route::post('/stripe/webhook', [StripeCheckoutController::class, 'webhook'])
    ->name('stripe.webhook');
