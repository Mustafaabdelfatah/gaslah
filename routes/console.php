<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Gaslah — auto-advance aged orders to ready every five minutes.
Schedule::command('automation:sweep')->everyFiveMinutes()->withoutOverlapping();

// Gaslah — subscription dunning cycle once a day (no-op while the policy is disabled).
Schedule::command('platform:dunning')->dailyAt('06:00')->withoutOverlapping();

// Gaslah — materialize recurring supplier bills and expenses once a day.
Schedule::command('payables:run-due')->dailyAt('05:30')->withoutOverlapping();
