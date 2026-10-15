<?php

namespace App\Services\Analytics;

use Carbon\CarbonInterface;

/**
 * Which `dashboard.greeting_*` key an hour falls under, in the account's
 * wall-clock time (the caller is expected to hand in a time already
 * converted to `config('agent.analytics.timezone')` — see that config's own
 * docblock: timestamps are stored in UTC, but a greeting is about the
 * owner's actual clock, same as the after-hours window and the heatmap).
 *
 * Night wraps midnight (23:00-04:59) rather than being "whatever morning
 * isn't" from the top of the day, so 1 a.m. does not read as "morning".
 */
class GreetingPeriod
{
    public static function key(CarbonInterface $now): string
    {
        $hour = $now->hour;

        return match (true) {
            $hour >= 5 && $hour <= 11 => 'dashboard.greeting_morning',
            $hour >= 12 && $hour <= 17 => 'dashboard.greeting_day',
            $hour >= 18 && $hour <= 22 => 'dashboard.greeting_evening',
            default => 'dashboard.greeting_night',
        };
    }
}
