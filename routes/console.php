<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Persistent recovery; immediate delivery also runs after each administrative commit.
Schedule::command('aaud-auth:deliver')->everyMinute()->withoutOverlapping(10);
