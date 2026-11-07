<?php

return [

    /*
    | Hours are interpreted in the application timezone.
    | open_weekdays uses ISO-8601: Monday is 1, Sunday is 7.
    */

    'opens_at' => '09:00',

    'closes_at' => '17:00',

    'open_weekdays' => [1, 2, 3, 4, 5],

    'appointments_per_page' => 15,

    'reminder_hours' => 24,

    /*
    | Minutes before starts_at when customers can no longer cancel or reschedule.
    | Admins are not affected. Set to 0 to disable the lock.
    */
    'modification_lock_minutes' => (int) env('BOOKING_MODIFICATION_LOCK_MINUTES', 120),

    'stats_days' => 30,

    'top_services' => 5,

];
