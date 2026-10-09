<?php

namespace App\Services\Bi\Ingestion;

/**
 * Decides what date format a column of text is written in — and says so when it
 * cannot be sure.
 *
 * This is the single hardest honest problem in ingestion, and it is worth being
 * explicit about why it cannot be fully solved: `03.04.2026` is the 3rd of April
 * to everyone in Dushanbe and the 4th of March to a spreadsheet saved in a US
 * locale, and NOTHING in the file distinguishes them. A parser that silently
 * picks one is wrong for half its users and tells neither half.
 *
 * So this votes, and reports its confidence:
 *
 *  1. PROOF beats preference. A column containing `17.03.2026` has proved
 *     day-first — 17 is not a month. One containing `03.17.2026` has proved
 *     month-first. Scanning for a component above 12 settles most real columns
 *     outright, because most date columns span more than twelve days.
 *  2. Where there is no proof, the CIS reading wins (day-first), because that is
 *     who this product is for — and `ambiguous` is set on the result, so the
 *     schema editor can show "assumed день.месяц" with one click to change it.
 *  3. Where both readings are proved, the column is genuinely inconsistent (two
 *     systems wrote into it). The majority format wins and `ambiguous` is set;
 *     the minority rows become counted cast failures rather than silently wrong
 *     dates.
 *
 * ISO-8601 is tried first and is never ambiguous, which is why `.xlsx` and `.xls`
 * dates — which arrive as real DateTimeImmutable objects from the readers and
 * never reach this class at all — are the easy case. Only text dates in CSV and
 * JSON need a vote.
 */
class DateFormatVoter
{
    /**
     * The formats tried, in preference order for equal evidence.
     *
     * `order` records what a successful parse would PROVE about the column, and
     * is the whole basis of the vote:
     *   'iso'   — unambiguous, year first.
     *   'dmy'   — day before month.
     *   'mdy'   — month before day.
     *   'other' — unambiguous for another reason (a named month, or y-m-d).
     *
     * @var array<int, array{format: string, order: string, time: bool}>
     */
    private const CANDIDATES = [
        // ISO-8601, in the spellings that actually occur. The offset variants
        // come first: `2026-03-01T10:00:00+05:00` must not be matched by the
        // shorter pattern and have its offset silently treated as trailing junk.
        ['format' => 'Y-m-d\TH:i:sP', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d\TH:i:s.uP', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d\TH:i:s', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d\TH:i', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d H:i:s', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d H:i', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d', 'order' => 'iso', 'time' => false],
        ['format' => 'Y/m/d', 'order' => 'other', 'time' => false],

        // Dotted — the CIS spelling, and the one Excel writes in a Russian
        // locale. Longest first, so a value carrying a time is not matched by
        // the date-only pattern with the time left over as junk.
        ['format' => 'd.m.Y H:i:s', 'order' => 'dmy', 'time' => true],
        ['format' => 'd.m.Y H:i', 'order' => 'dmy', 'time' => true],
        ['format' => 'd.m.Y', 'order' => 'dmy', 'time' => false],
        ['format' => 'd.m.y', 'order' => 'dmy', 'time' => false],

        // Slashed and dashed, in both readings. Both orders are offered for the
        // same separator on purpose: the vote decides between them from evidence
        // rather than this list's order deciding it silently.
        ['format' => 'd/m/Y H:i:s', 'order' => 'dmy', 'time' => true],
        ['format' => 'd/m/Y H:i', 'order' => 'dmy', 'time' => true],
        ['format' => 'd/m/Y', 'order' => 'dmy', 'time' => false],
        ['format' => 'm/d/Y H:i:s', 'order' => 'mdy', 'time' => true],
        ['format' => 'm/d/Y H:i', 'order' => 'mdy', 'time' => true],
        ['format' => 'm/d/Y', 'order' => 'mdy', 'time' => false],
        ['format' => 'd-m-Y', 'order' => 'dmy', 'time' => false],
        ['format' => 'm-d-Y', 'order' => 'mdy', 'time' => false],
        ['format' => 'd/m/y', 'order' => 'dmy', 'time' => false],
        ['format' => 'm/d/y', 'order' => 'mdy', 'time' => false],
    ];

    /** @var array<string, int> format => how many sampled values it parsed */
    private array $matches = [];

    private int $observed = 0;

    /** Set when a value's first numeric component exceeded 12. */
    private bool $dayFirstProved = false;

    /** Set when a value's second numeric component exceeded 12. */
    private bool $monthFirstProved = false;

    /**
     * Offer one value to the vote. Blank values must not be passed — a blank is
     * neither evidence for nor against any format.
     */
    public function observe(string $value): void
    {
        $value = MonthNames::fold(trim($value));

        if ($value === '') {
            return;
        }

        $this->observed++;
        $this->noteComponentEvidence($value);

        foreach (self::CANDIDATES as $candidate) {
            if (StrictDate::matches($value, $candidate['format'])) {
                $this->matches[$candidate['format']] = ($this->matches[$candidate['format']] ?? 0) + 1;
            }
        }
    }

    /**
     * The winning format, or null when nothing parsed a meaningful share.
     *
     * @return array{format: string, has_time: bool, order: string, ambiguous: bool, matched: int, observed: int}|null
     */
    public function decide(): ?array
    {
        if ($this->observed === 0 || $this->matches === []) {
            return null;
        }

        $preferredOrder = $this->preferredOrder();

        $best = null;
        $bestScore = 0;

        foreach (self::CANDIDATES as $candidate) {
            $matched = $this->matches[$candidate['format']] ?? 0;

            if ($matched === 0) {
                continue;
            }

            // A format whose component order the evidence has RULED OUT cannot
            // win, however many values it happened to parse: in a column proved
            // day-first, `m/d/Y` still parses every value whose day is under 13,
            // and letting that count would hand the column to the reading the
            // data has already disproved.
            if ($candidate['order'] !== 'iso' && $candidate['order'] !== 'other'
                && $preferredOrder !== null && $candidate['order'] !== $preferredOrder) {
                continue;
            }

            // Strictly greater, so CANDIDATES order breaks ties — which is what
            // puts ISO ahead of everything and the longest (time-bearing)
            // spelling of each family ahead of its shorter siblings.
            if ($matched > $bestScore) {
                $best = $candidate;
                $bestScore = $matched;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'format' => $best['format'],
            'has_time' => $best['time'],
            'order' => $best['order'],
            // Only a day/month-order format can be ambiguous, and only when the
            // data did not settle it. This flag is what the schema editor shows
            // as "assumed день.месяц" — the honest disclosure.
            'ambiguous' => in_array($best['order'], ['dmy', 'mdy'], true)
                && ! ($this->dayFirstProved xor $this->monthFirstProved),
            'matched' => $bestScore,
            'observed' => $this->observed,
        ];
    }

    /**
     * Which component order the sampled values support, or null when they are
     * silent or contradictory.
     */
    private function preferredOrder(): ?string
    {
        return match (true) {
            $this->dayFirstProved && ! $this->monthFirstProved => 'dmy',
            $this->monthFirstProved && ! $this->dayFirstProved => 'mdy',
            // Contradictory (both proved) or silent (neither): fall through to
            // the CANDIDATES order, which prefers the CIS reading. `ambiguous`
            // is set for both cases so the owner is told.
            default => null,
        };
    }

    /**
     * Look at the first two numeric components of a value and record what they
     * prove about the column's order.
     *
     * Only for values whose third component looks like a year — `12/24` is a
     * fraction or a score, not a date, and must not prove anything.
     */
    private function noteComponentEvidence(string $value): void
    {
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})\b/', $value, $m) !== 1) {
            return;
        }

        if ((int) $m[1] > 12) {
            $this->dayFirstProved = true;
        }

        if ((int) $m[2] > 12) {
            $this->monthFirstProved = true;
        }
    }
}
