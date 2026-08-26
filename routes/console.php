<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Gaslah — auto-advance aged orders to ready every five minutes.
Schedule::command('automation:sweep')->everyFiveMinutes()->withoutOverlapping();
