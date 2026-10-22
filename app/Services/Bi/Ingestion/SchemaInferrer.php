<?php

namespace App\Services\Bi\Ingestion;

use App\Models\BiColumn;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;

class SchemaInferrer
{
    private const UNIQUENESS_EVIDENCE_ROWS = 20;

    private array $headers;

    private array $stats = [];

    private int $rowsObserved = 0;

    public function __construct(
        array $headers,
        private readonly TypeCaster $caster = new TypeCaster,
    ) {
        $this->headers = array_values($headers);

        foreach ($this->headers as $ordinal => $_) {
            $this->stats[$ordinal] = [
                'nonBlank' => 0,
                'blank' => 0,
                'maxLength' => 0,
                'casts' => [],
                'distinct' => [],
                'distinctOverflowed' => false,
                'samples' => [],
                'voter' => new DateFormatVoter,
                'dateCandidates' => 0,
                'min' => null,
                'max' => null,
                'minText' => null,
                'maxText' => null,
            ];
        }
    }

    public function wantsMore(): bool
    {
        return $this->rowsObserved < max(1, (int) config('agent.bi.ingest.inference_rows', 5000));
    }

    public function observe(array $row): void
    {
        if (! $this->wantsMore()) {
            return;
        }

        $this->rowsObserved++;

        foreach ($this->headers as $ordinal => $_) {
            $this->observeCell($ordinal, $row[$ordinal] ?? null);
        }
    }

    private function observeCell(int $ordinal, mixed $raw): void
    {
        $stats = &$this->stats[$ordinal];

        if ($raw instanceof \DateTimeInterface) {
            $stats['nonBlank']++;
            $stats['casts'][ColumnType::Date->value] = ($stats['casts'][ColumnType::Date->value] ?? 0) + 1;

            if ($raw->format('H:i:s') !== '00:00:00') {
                $stats['casts'][ColumnType::DateTime->value] = ($stats['casts'][ColumnType::DateTime->value] ?? 0) + 1;
            }

            $text = $raw->format('Y-m-d H:i:s');
            $this->noteText($stats, $text);
            $this->noteTextExtremes($stats, $raw->format('Y-m-d H:i:s'));

            return;
        }

        if ($raw === null) {
            $stats['blank']++;

            return;
        }

        if (is_int($raw) || is_float($raw)) {
            $stats['nonBlank']++;
            $stats['casts'][ColumnType::Decimal->value] = ($stats['casts'][ColumnType::Decimal->value] ?? 0) + 1;

            if (is_int($raw) || $raw === floor((float) $raw)) {
                $stats['casts'][ColumnType::Integer->value] = ($stats['casts'][ColumnType::Integer->value] ?? 0) + 1;
            }

            $this->noteNumber($stats, (float) $raw);
            $this->noteText($stats, $this->caster->clean((string) $raw));

            return;
        }

        if (is_bool($raw)) {
            $stats['nonBlank']++;
            $stats['casts'][ColumnType::Boolean->value] = ($stats['casts'][ColumnType::Boolean->value] ?? 0) + 1;
            $this->noteText($stats, $raw ? 'true' : 'false');

            return;
        }

        $value = $this->caster->clean((string) $raw);

        if ($this->caster->isBlank($value)) {
            $stats['blank']++;

            return;
        }

        $stats['nonBlank']++;
        $this->noteText($stats, $value);

        $decimal = $this->caster->cast($value, ColumnType::Decimal);
        $integer = $this->caster->cast($value, ColumnType::Integer);
        $boolean = $this->caster->cast($value, ColumnType::Boolean);

        foreach ([ColumnType::Decimal->value => $decimal, ColumnType::Integer->value => $integer, ColumnType::Boolean->value => $boolean] as $key => $result) {
            if ($result->isUsable()) {
                $stats['casts'][$key] = ($stats['casts'][$key] ?? 0) + 1;
            }
        }

        if ($decimal->isUsable()) {
            $this->noteNumber($stats, (float) $decimal->value);
        } else {
            $this->noteTextExtremes($stats, $value);
        }

        if ($this->looksLikeDate($value)) {
            $stats['dateCandidates']++;
            $stats['voter']->observe($value);
        }
    }

    private function looksLikeDate(string $value): bool
    {
        if (mb_strlen($value) > 40 || preg_match('/\d/', $value) !== 1) {
            return false;
        }

        return preg_match('/\d[.\/-]\d/', $value) === 1
            || preg_match('/^\d{1,2}[\s.]+\p{L}{3,}/u', $value) === 1;
    }

    private function noteText(array &$stats, string $value): void
    {
        $stats['maxLength'] = max($stats['maxLength'], mb_strlen($value));

        $limit = max(2, (int) config('agent.bi.ingest.max_distinct_tracked', 1001));

        if (isset($stats['distinct'][$value])) {
            return;
        }

        if (count($stats['distinct']) >= $limit) {

            $stats['distinctOverflowed'] = true;

            return;
        }

        $stats['distinct'][$value] = true;

        $sampleLimit = max(1, (int) config('agent.bi.ingest.sample_values', 5));

        if (count($stats['samples']) < $sampleLimit) {
            $stats['samples'][] = mb_substr($value, 0, 200);
        }
    }

    private function noteNumber(array &$stats, float $value): void
    {
        $stats['min'] = $stats['min'] === null ? $value : min($stats['min'], $value);
        $stats['max'] = $stats['max'] === null ? $value : max($stats['max'], $value);
    }

    private function noteTextExtremes(array &$stats, string $value): void
    {
        $stats['minText'] = $stats['minText'] === null ? $value : min($stats['minText'], $value);
        $stats['maxText'] = $stats['maxText'] === null ? $value : max($stats['maxText'], $value);
    }

    public function columns(): array
    {
        $threshold = (float) config('agent.bi.ingest.inference_threshold', 0.95);
        $dimensionLimit = max(1, (int) config('agent.bi.ingest.dimension_distinct_limit', 1000));

        $columns = [];

        foreach ($this->headers as $ordinal => $header) {
            $stats = $this->stats[$ordinal];
            $nonBlank = $stats['nonBlank'];

            [$type, $format] = $this->decideType($stats, $nonBlank, $threshold);

            $distinct = count($stats['distinct']);

            $columns[] = new InferredColumn(
                ordinal: $ordinal,
                sourceName: $header,
                physicalName: BiColumn::physicalNameFor($ordinal),
                type: $type,
                role: $type->defaultRole($this->looksLikeYear($type, $stats)),
                format: $format,
                distinctCount: $distinct,
                distinctExact: ! $stats['distinctOverflowed'],
                blankCount: $stats['blank'],
                nonBlankCount: $stats['nonBlank'],
                maxLength: $stats['maxLength'],
                sampleValues: $stats['samples'],
                minValue: $this->extreme($stats, 'min'),
                maxValue: $this->extreme($stats, 'max'),

                indexed: false,
            );
        }

        return $this->applyIndexBudget($columns, $dimensionLimit);
    }

    private function decideType(array $stats, int $nonBlank, float $threshold): array
    {
        if ($nonBlank === 0) {

            return [ColumnType::Text, []];
        }

        $needed = (int) ceil($threshold * $nonBlank);

        $decision = $stats['voter']->decide();

        if ($decision !== null) {
            $matched = $decision['matched'];

            $native = $stats['casts'][ColumnType::Date->value] ?? 0;

            if ($matched + $native >= $needed) {
                return [
                    $decision['has_time'] ? ColumnType::DateTime : ColumnType::Date,
                    [
                        'date_format' => $decision['format'],
                        'date_order' => $decision['order'],

                        'date_ambiguous' => $decision['ambiguous'],
                    ],
                ];
            }
        }

        $nativeDates = $stats['casts'][ColumnType::Date->value] ?? 0;

        if ($nativeDates >= $needed) {
            $withTime = $stats['casts'][ColumnType::DateTime->value] ?? 0;

            return [$withTime > 0 ? ColumnType::DateTime : ColumnType::Date, []];
        }

        if (($stats['casts'][ColumnType::Integer->value] ?? 0) >= $needed) {
            return [ColumnType::Integer, []];
        }

        if (($stats['casts'][ColumnType::Decimal->value] ?? 0) >= $needed) {
            return [ColumnType::Decimal, []];
        }

        if ($this->isBooleanColumn($stats, $nonBlank)) {
            return [ColumnType::Boolean, []];
        }

        return [ColumnType::Text, []];
    }

    private function isBooleanColumn(array $stats, int $nonBlank): bool
    {

        if ($stats['distinctOverflowed'] || $stats['distinct'] === []) {
            return false;
        }

        if (count($stats['distinct']) > 4) {
            return false;
        }

        return ($stats['casts'][ColumnType::Boolean->value] ?? 0) === $nonBlank;
    }

    private function looksLikeYear(ColumnType $type, array $stats): bool
    {
        if ($type !== ColumnType::Integer) {
            return false;
        }

        $min = $stats['min'];
        $max = $stats['max'];

        if ($min === null || $max === null) {
            return false;
        }

        if ($stats['distinctOverflowed'] || count($stats['distinct']) > 300) {
            return false;
        }

        return $min >= 1900.0 && $max <= 2200.0;
    }

    private function extreme(array $stats, string $which): ?string
    {
        $number = $stats[$which];

        if ($number !== null) {
            return rtrim(rtrim(number_format((float) $number, 6, '.', ''), '0'), '.') ?: '0';
        }

        $text = $stats[$which.'Text'];

        return $text === null ? null : mb_substr((string) $text, 0, 512);
    }

    private function applyIndexBudget(array $columns, int $dimensionLimit): array
    {
        $budget = max(0, (int) config('agent.bi.ingest.max_indexes', 24));

        $eligible = [];

        foreach ($columns as $position => $column) {
            if ($column->type->isTemporal()) {
                $eligible[$position] = 0;

                continue;
            }

            if ($column->role !== ColumnRole::Dimension) {
                continue;
            }

            if ($column->isLongText() || ! $column->distinctExact || $column->distinctCount > $dimensionLimit) {
                continue;
            }

            if ($column->distinctCount <= 1) {
                continue;
            }

            if ($column->nonBlankCount >= self::UNIQUENESS_EVIDENCE_ROWS
                && $column->distinctCount >= $column->nonBlankCount) {
                continue;
            }

            $eligible[$position] = 1 + $column->distinctCount;
        }

        asort($eligible);
        $chosen = array_slice(array_keys($eligible), 0, $budget, true);

        foreach ($chosen as $position) {
            $column = $columns[$position];

            $columns[$position] = new InferredColumn(
                ordinal: $column->ordinal,
                sourceName: $column->sourceName,
                physicalName: $column->physicalName,
                type: $column->type,
                role: $column->role,
                format: $column->format,
                distinctCount: $column->distinctCount,
                distinctExact: $column->distinctExact,
                blankCount: $column->blankCount,
                nonBlankCount: $column->nonBlankCount,
                maxLength: $column->maxLength,
                sampleValues: $column->sampleValues,
                minValue: $column->minValue,
                maxValue: $column->maxValue,
                indexed: true,
            );
        }

        return $columns;
    }
}
