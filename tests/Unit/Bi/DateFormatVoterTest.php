<?php

namespace Tests\Unit\Bi;

use App\Services\Bi\Ingestion\DateFormatVoter;
use App\Services\Bi\Ingestion\MonthNames;
use PHPUnit\Framework\TestCase;

/**
 * The honest-uncertainty tests.
 *
 * `03.04.2026` cannot be resolved by any parser: it is the 3rd of April in
 * Dushanbe and the 4th of March in a US-locale spreadsheet, and the file does not
 * say which. So what is being asserted here is not only "it picks the right
 * format" but "it KNOWS when it was guessing" — because the `ambiguous` flag is
 * what the schema editor shows the owner, and an unflagged guess is the failure
 * mode that silently misdates a whole dashboard.
 */
class DateFormatVoterTest extends TestCase
{
    /**
     * @param  array<int, string>  $values
     */
    private function vote(array $values): ?array
    {
        $voter = new DateFormatVoter;

        foreach ($values as $value) {
            $voter->observe($value);
        }

        return $voter->decide();
    }

    public function test_iso_wins_outright_and_is_never_ambiguous(): void
    {
        $decision = $this->vote(['2026-03-01', '2026-03-02', '2026-11-30']);

        $this->assertSame('Y-m-d', $decision['format']);
        $this->assertFalse($decision['has_time']);
        $this->assertFalse($decision['ambiguous']);
    }

    public function test_an_iso_timestamp_keeps_its_time(): void
    {
        $decision = $this->vote(['2026-03-01 09:15:00', '2026-03-02 17:40:00']);

        $this->assertTrue($decision['has_time'], 'A column carrying a clock must become a datetime, not a date.');
        $this->assertSame('Y-m-d H:i:s', $decision['format']);
    }

    public function test_an_offset_timestamp_is_not_matched_by_the_shorter_pattern(): void
    {
        // The danger: `Y-m-d\TH:i:s` would match the first 19 characters and leave
        // `+05:00` as trailing junk. Strict parsing is what refuses that.
        $decision = $this->vote(['2026-03-01T09:15:00+05:00', '2026-03-02T17:40:00+05:00']);

        $this->assertSame('Y-m-d\TH:i:sP', $decision['format']);
        $this->assertTrue($decision['has_time']);
    }

    /**
     * PROOF beats preference. A 17 cannot be a month, so this column has settled
     * its own order and nothing was guessed.
     */
    public function test_a_day_above_twelve_proves_day_first(): void
    {
        $decision = $this->vote(['01.03.2026', '17.03.2026', '28.03.2026']);

        $this->assertSame('d.m.Y', $decision['format']);
        $this->assertSame('dmy', $decision['order']);
        $this->assertFalse($decision['ambiguous'], 'A column containing 17.03 has proved its own order.');
    }

    public function test_a_second_component_above_twelve_proves_month_first(): void
    {
        $decision = $this->vote(['03/17/2026', '03/25/2026', '01/09/2026']);

        $this->assertSame('m/d/Y', $decision['format']);
        $this->assertSame('mdy', $decision['order']);
        $this->assertFalse($decision['ambiguous']);
    }

    /**
     * The case that cannot be solved. Every component is under 13, so both readings
     * parse every value — the CIS reading is preferred AND the result says it was a
     * preference.
     */
    public function test_a_genuinely_ambiguous_column_prefers_day_first_and_admits_it(): void
    {
        $decision = $this->vote(['01/02/2026', '03/04/2026', '05/06/2026']);

        $this->assertSame('dmy', $decision['order'], 'A CIS product should read an unsettled column as day-first.');
        $this->assertTrue(
            $decision['ambiguous'],
            'Nothing in this column settles the order, so the owner has to be told it was assumed.'
        );
    }

    /**
     * Two systems wrote into one column. The majority wins so most rows are right,
     * the minority become counted cast failures rather than silently wrong dates,
     * and `ambiguous` is set because the column contradicts itself.
     */
    public function test_a_contradictory_column_takes_the_majority_and_admits_it(): void
    {
        $decision = $this->vote([
            '17.03.2026', '18.03.2026', '19.03.2026', '20.03.2026',
            '03.17.2026',
        ]);

        $this->assertSame('dmy', $decision['order']);
        $this->assertTrue($decision['ambiguous'], 'A column proving both readings is contradictory, not confident.');
    }

    public function test_written_out_months_are_recognised_in_all_three_languages(): void
    {
        foreach ([
            ['15 марта 2026', '15 апреля 2026'],
            ['15 марти 2026', '20 апрели 2026'],
            ['15 March 2026', '20 April 2026'],
        ] as $values) {
            $decision = $this->vote($values);

            $this->assertNotNull($decision, 'A written-out month column should be recognised: '.implode(', ', $values));
            $this->assertSame('dmy', $decision['order']);
        }
    }

    public function test_month_folding_keeps_a_trailing_time(): void
    {
        $this->assertSame('15.03.2026 14:30', MonthNames::fold('15 марта 2026 14:30'));
        $this->assertSame('15.03.2026', MonthNames::fold('15 марта 2026'));
        // Anything that is not a written-out month passes through untouched.
        $this->assertSame('15.03.2026', MonthNames::fold('15.03.2026'));
        $this->assertSame('не дата', MonthNames::fold('не дата'));
    }

    public function test_a_column_of_non_dates_produces_no_decision(): void
    {
        $this->assertNull($this->vote(['куртка', 'джинсы', 'футболка']));
        $this->assertNull($this->vote([]));
        // A ratio or a score, not a date: no year-shaped third component.
        $this->assertNull($this->vote(['12/24', '3/4']));
    }

    /**
     * An impossible date must not be "recognised" and then rolled forward.
     * `createFromFormat` would turn 31.02 into the 3rd of March with only a
     * warning, so the warning count is part of the check.
     */
    public function test_impossible_dates_do_not_vote(): void
    {
        $this->assertNull($this->vote(['31.02.2026', '32.01.2026', '45.13.2026']));
    }

    public function test_two_digit_years_are_recognised(): void
    {
        $decision = $this->vote(['17.03.26', '18.03.26']);

        $this->assertSame('d.m.y', $decision['format']);
    }
}
