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

/*
 * Nightly database backup.
 *
 * Cleanup runs first so the disk is pruned before a new dump is written rather
 * than after, which keeps the peak footprint one backup lower.
 *
 * withoutOverlapping() matters here: a dump that runs long must not have a
 * second one started on top of it.
 *
 * NOTE: like the watchdog above, this only fires where something actually runs
 * `schedule:run` every minute. In local OSPanel dev nothing does — see
 * BACKUPS.md for the Windows Task Scheduler entry that closes that gap. Until
 * that is set up, backups happen only when `php artisan backup:run` is run by
 * hand.
 */
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run --only-db')->dailyAt('01:30')->withoutOverlapping();
