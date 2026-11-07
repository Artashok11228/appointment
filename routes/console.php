<?php

use Illuminate\Support\Facades\Schedule;

// reminded_at already stops a second mail, so hourly is enough to catch new bookings.
Schedule::command('appointments:send-reminders')->hourly();

Schedule::command('appointments:complete-past')->hourly();
