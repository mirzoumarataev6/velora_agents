<?php

namespace App\Services\Analytics;

use Carbon\CarbonInterface;

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
