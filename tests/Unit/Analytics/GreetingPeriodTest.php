<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\GreetingPeriod;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four dashboard.greeting_* ranges, pinned at every boundary hour so a
 * future edit can't quietly shift one without a test noticing. Night wraps
 * midnight (23:00-04:59), which is the one range that isn't a simple
 * low <= hour <= high check.
 */
class GreetingPeriodTest extends TestCase
{
    #[DataProvider('boundaryHours')]
    public function test_each_boundary_hour_resolves_to_the_right_key(int $hour, string $expectedKey): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 1, $hour, 0, 0, 'Asia/Dushanbe'));

        $now = CarbonImmutable::now('Asia/Dushanbe');

        $this->assertSame($expectedKey, GreetingPeriod::key($now));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function boundaryHours(): array
    {
        return [
            '4 is still night' => [4, 'dashboard.greeting_night'],
            '5 starts morning' => [5, 'dashboard.greeting_morning'],
            '11 is still morning' => [11, 'dashboard.greeting_morning'],
            '12 starts day' => [12, 'dashboard.greeting_day'],
            '17 is still day' => [17, 'dashboard.greeting_day'],
            '18 starts evening' => [18, 'dashboard.greeting_evening'],
            '22 is still evening' => [22, 'dashboard.greeting_evening'],
            '23 starts night' => [23, 'dashboard.greeting_night'],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
