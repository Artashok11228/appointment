<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// reminded_at already stops a second mail, so hourly is enough to catch new bookings.
Schedule::command('appointments:send-reminders')->hourly();
