<?php

return [

    // Currency (ISO 4217) until one is chosen in Setup or Instance settings.
    'currency' => 'EUR',

    // Reminder urgency thresholds used until a user saves their own.
    'reminders' => [
        'urgent_days' => 30,
        'very_urgent_days' => 7,
        'urgent_distance' => 1000,
        'very_urgent_distance' => 300,
    ],

];
