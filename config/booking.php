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

];
