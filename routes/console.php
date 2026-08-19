<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover knowledge sources abandoned by a dead or hung worker. In local dev
// nothing runs the scheduler (that would be another permanent php.exe on a
// setup where process count is the problem) — `queue:supervise` calls the
// watchdog in-process on the same interval instead. This registration is for
// a real deployment, where cron drives `schedule:run`.
Schedule::command('queue:watchdog')->everyMinute()->withoutOverlapping();
