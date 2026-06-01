<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filament database notifications polling
    |--------------------------------------------------------------------------
    |
    | How often Livewire polls for new in-app notifications (bell icon).
    | Examples: 5s, 10s, 30s. Set to null to disable polling (not recommended).
    |
    */
    'database_notifications_polling' => env('FILAMENT_DATABASE_NOTIFICATIONS_POLLING', '10s'),

];
