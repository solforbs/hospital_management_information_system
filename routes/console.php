<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily SMS reminders (appointments, ANC visits, immunisations due tomorrow).
Schedule::command('hms:send-reminders')->dailyAt('08:00')->withoutOverlapping();

// Keep sitemap.xml fresh for search engines.
Schedule::command('hms:generate-sitemap')->dailyAt('02:30')->withoutOverlapping();
