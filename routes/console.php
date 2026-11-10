<?php

use App\Jobs\AutoMessage\DispatchScheduledAutoMessagesJob;
use App\Jobs\Beds24\SyncBookingsJob;
use App\Jobs\Beds24\SyncMessagesJob;
use App\Jobs\Beds24\SyncRoomBlocksJob;
use App\Jobs\CompressGuestIdImagesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncMessagesJob)
    ->everyFiveMinutes()
    ->name('beds24:sync-messages')
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::job(new SyncBookingsJob)
    ->everyFifteenMinutes()
    ->name('beds24:sync-bookings')
    ->onOneServer()
    ->withoutOverlapping(15);

Schedule::job(new SyncRoomBlocksJob)
    ->everyThirtyMinutes()
    ->name('beds24:sync-room-blocks')
    ->onOneServer()
    ->withoutOverlapping(20);

Schedule::job(new CompressGuestIdImagesJob)
    ->dailyAt('03:15')
    ->name('guest-id:compress-images')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::job(new DispatchScheduledAutoMessagesJob)
    ->everyMinute()
    ->name('auto-message:dispatch')
    ->onOneServer()
    ->withoutOverlapping(2);
