<?php

namespace App\Services\Bi\Ingestion;

class DateFormatVoter
{
    private const CANDIDATES = [

        ['format' => 'Y-m-d\TH:i:sP', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d\TH:i:s.uP', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d\TH:i:s', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d\TH:i', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d H:i:s', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d H:i', 'order' => 'iso', 'time' => true],
        ['format' => 'Y-m-d', 'order' => 'iso', 'time' => false],
        ['format' => 'Y/m/d', 'order' => 'other', 'time' => false],

        ['format' => 'd.m.Y H:i:s', 'order' => 'dmy', 'time' => true],
        ['format' => 'd.m.Y H:i', 'order' => 'dmy', 'time' => true],
        ['format' => 'd.m.Y', 'order' => 'dmy', 'time' => false],
        ['format' => 'd.m.y', 'order' => 'dmy', 'time' => false],

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

    private array $matches = [];

    private int $observed = 0;

    private bool $dayFirstProved = false;

    private bool $monthFirstProved = false;

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

            if ($candidate['order'] !== 'iso' && $candidate['order'] !== 'other'
                && $preferredOrder !== null && $candidate['order'] !== $preferredOrder) {
                continue;
            }

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

            'ambiguous' => in_array($best['order'], ['dmy', 'mdy'], true)
                && ! ($this->dayFirstProved xor $this->monthFirstProved),
            'matched' => $bestScore,
            'observed' => $this->observed,
        ];
    }

    private function preferredOrder(): ?string
    {
        return match (true) {
            $this->dayFirstProved && ! $this->monthFirstProved => 'dmy',
            $this->monthFirstProved && ! $this->dayFirstProved => 'mdy',

            default => null,
        };
    }

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
