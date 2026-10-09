<?php

namespace Tests\Unit\Bi;

use App\Services\Bi\Ingestion\InferredColumn;
use App\Services\Bi\Ingestion\SchemaInferrer;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Inference is where the product decides what an owner's spreadsheet MEANS, and the
 * decision that matters most is when to refuse to decide: a column is only given a
 * type when 95% of its non-blank values fit, and is text otherwise.
 *
 * Extends the app TestCase rather than plain PHPUnit because the thresholds and
 * budgets are configuration, and the point of several of these tests is that the
 * configured number is what is enforced.
 */
class SchemaInferrerTest extends TestCase
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, InferredColumn>
     */
    private function infer(array $headers, array $rows): array
    {
        $inferrer = new SchemaInferrer($headers);

        foreach ($rows as $row) {
            $inferrer->observe($row);
        }

        return $inferrer->columns();
    }

    public function test_it_recognises_the_ordinary_types(): void
    {
        $columns = $this->infer(
            ['Дата', 'Товар', 'Количество', 'Сумма', 'Оплачен'],
            [
                ['01.03.2026', 'Куртка', '2', '1 500,50', 'да'],
                ['17.03.2026', 'Джинсы', '1', '2 400,00', 'нет'],
                ['28.03.2026', 'Куртка', '5', '750,25', 'да'],
            ]
        );

        $this->assertSame(ColumnType::Date, $columns[0]->type);
        $this->assertSame(ColumnType::Text, $columns[1]->type);
        $this->assertSame(ColumnType::Integer, $columns[2]->type);
        $this->assertSame(ColumnType::Decimal, $columns[3]->type);
        $this->assertSame(ColumnType::Boolean, $columns[4]->type);
    }

    public function test_physical_names_are_ours_and_never_come_from_the_file(): void
    {
        // The header can contain anything at all — quotes, semicolons, a DROP
        // statement — because it is only ever data. The identifier is positional.
        $columns = $this->infer(
            ['"; DROP TABLE users; --', 'Сумма'],
            [['x', '1'], ['y', '2']]
        );

        $this->assertSame('c0', $columns[0]->physicalName);
        $this->assertSame('c1', $columns[1]->physicalName);
        $this->assertSame('"; DROP TABLE users; --', $columns[0]->sourceName);
    }

    /**
     * THE CENTRAL RULE. A "Цена" column where most rows are numbers and some say
     * "по запросу" is not a numeric column with holes in it — calling it one would
     * let an owner build a revenue chart that silently omits part of their business.
     */
    public function test_a_column_below_the_threshold_stays_text(): void
    {
        $rows = [];

        // 80% numbers, 20% words — well under the 95% threshold.
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['1500'];
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = ['по запросу'];
        }

        $columns = $this->infer(['Цена'], $rows);

        $this->assertSame(
            ColumnType::Text,
            $columns[0]->type,
            'A mixed column must stay text rather than quietly dropping its minority values.'
        );
    }

    public function test_a_column_at_the_threshold_gets_its_type(): void
    {
        $rows = [];

        // 96 numbers, 4 words — just over 95%. The four become counted cast
        // failures at materialization, which the owner is shown.
        for ($i = 0; $i < 96; $i++) {
            $rows[] = ['1500'];
        }
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['по запросу'];
        }

        $this->assertSame(ColumnType::Integer, $this->infer(['Цена'], $rows)[0]->type);
    }

    /**
     * Blanks are not evidence against a type. A column that is 90% empty and 10%
     * clean dates is a date column.
     */
    public function test_blanks_are_excluded_from_the_denominator(): void
    {
        $rows = [['01.03.2026'], [null], [null], [null], ['—'], ['17.03.2026'], [''], ['не указано']];

        $columns = $this->infer(['Дата отгрузки'], $rows);

        $this->assertSame(ColumnType::Date, $columns[0]->type);
        $this->assertSame(6, $columns[0]->blankCount);
    }

    /**
     * A column of zeroes and ones is far more often a count or an id than a flag,
     * and making it boolean would make SUM over it meaningless.
     */
    public function test_zeroes_and_ones_do_not_make_a_column_boolean(): void
    {
        $columns = $this->infer(['Активен'], [['1'], ['0'], ['1'], ['1'], ['0']]);

        $this->assertSame(ColumnType::Integer, $columns[0]->type);
    }

    /**
     * Boolean needs EVERY distinct value to be a truth word, not merely the
     * threshold share: "да / нет / возможно" is a three-valued category, and
     * flattening the third to NULL would lose a real answer.
     */
    public function test_a_third_value_disqualifies_a_boolean_column(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['да'];
        }
        for ($i = 0; $i < 49; $i++) {
            $rows[] = ['нет'];
        }
        $rows[] = ['возможно'];

        $this->assertSame(ColumnType::Text, $this->infer(['Подтверждён'], $rows)[0]->type);
    }

    public function test_a_real_date_cell_is_recognised_without_voting_and_keeps_its_time(): void
    {
        $withClock = $this->infer(['Создан'], [
            [new DateTimeImmutable('2026-03-01 09:15:00')],
            [new DateTimeImmutable('2026-03-02 17:40:00')],
        ]);

        $this->assertSame(ColumnType::DateTime, $withClock[0]->type);

        // Midnight across the board means the cells carried a date and no clock.
        $dateOnly = $this->infer(['Дата'], [
            [new DateTimeImmutable('2026-03-01 00:00:00')],
            [new DateTimeImmutable('2026-03-02 00:00:00')],
        ]);

        $this->assertSame(ColumnType::Date, $dateOnly[0]->type);
    }

    public function test_roles_follow_the_type_and_the_cardinality(): void
    {
        $columns = $this->infer(
            ['Дата', 'Регион', 'Выручка', 'Год'],
            [
                ['01.03.2026', 'Душанбе', '1500.50', '2025'],
                ['02.03.2026', 'Худжанд', '2400.00', '2026'],
                ['03.03.2026', 'Душанбе', '900.10', '2026'],
            ]
        );

        $this->assertSame(ColumnRole::Time, $columns[0]->role);
        $this->assertSame(ColumnRole::Dimension, $columns[1]->role);
        $this->assertSame(ColumnRole::Measure, $columns[2]->role);
        // A year is an integer, but summing it is meaningless — low cardinality is
        // what saves this very common case.
        $this->assertSame(ColumnRole::Dimension, $columns[3]->role);
    }

    public function test_an_all_blank_column_is_kept_as_text_rather_than_dropped(): void
    {
        // A column that is empty in this export may not be in the next one.
        $columns = $this->infer(['Комментарий'], [[null], [''], ['—']]);

        $this->assertCount(1, $columns);
        $this->assertSame(ColumnType::Text, $columns[0]->type);
        $this->assertSame(0, $columns[0]->distinctCount);
    }

    public function test_it_records_what_it_saw_for_the_schema_editor(): void
    {
        $columns = $this->infer(['Регион'], [['Душанбе'], ['Худжанд'], ['Душанбе'], ['Бохтар']]);

        $this->assertSame(3, $columns[0]->distinctCount);
        $this->assertTrue($columns[0]->distinctExact);
        $this->assertSame(['Душанбе', 'Худжанд', 'Бохтар'], $columns[0]->sampleValues);
        $this->assertSame(7, $columns[0]->maxLength);
    }

    public function test_an_ambiguous_date_column_carries_the_warning_into_its_format(): void
    {
        $columns = $this->infer(['Дата'], [['01/02/2026'], ['03/04/2026'], ['05/06/2026']]);

        $this->assertSame(ColumnType::Date, $columns[0]->type);
        $this->assertTrue(
            $columns[0]->format['date_ambiguous'],
            'The schema editor needs this flag to tell the owner the order was assumed.'
        );
    }

    /**
     * Two separate judgements: whether a column is WORTH indexing, and how many
     * indexes the table can afford. A measure is never indexed, an id-like column
     * is never indexed, and a time column claims the budget first.
     */
    public function test_indexes_go_to_time_columns_and_low_cardinality_dimensions_only(): void
    {
        $rows = [];
        $regions = ['Душанбе', 'Худжанд', 'Бохтар'];

        // Enough rows for the "every value is distinct" test to have evidence —
        // see SchemaInferrer::UNIQUENESS_EVIDENCE_ROWS for why the floor exists.
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = [
                sprintf('%02d.03.2026', ($i % 28) + 1),
                $regions[$i % 3],
                (string) (1000 + $i).'.50',
                "ORD-{$i}",
            ];
        }

        $columns = $this->infer(['Дата', 'Регион', 'Выручка', 'Номер заказа'], $rows);

        $this->assertTrue($columns[0]->indexed, 'Every dashboard filters by date.');
        $this->assertTrue($columns[1]->indexed, 'A low-cardinality dimension is what a GROUP BY needs.');
        $this->assertFalse($columns[2]->indexed, 'Nothing groups by revenue.');
        $this->assertFalse(
            $columns[3]->indexed,
            'A column where every row differs is an order number, not a dimension.'
        );
    }

    /**
     * The same shape with too few rows keeps its index, and that is deliberate: in a
     * five-row sample "every value is distinct" is equally true of a real category,
     * so there is not enough evidence to refuse. Asserted so the limitation is
     * recorded rather than discovered.
     */
    public function test_uniqueness_needs_evidence_before_it_disqualifies_a_column(): void
    {
        $columns = $this->infer(['Регион'], [['Душанбе'], ['Худжанд'], ['Бохтар']]);

        $this->assertTrue($columns[0]->indexed);
    }

    public function test_the_index_budget_is_enforced(): void
    {
        config(['agent.bi.ingest.max_indexes' => 2]);

        $headers = [];
        $row = [];

        // Ten columns that would each qualify on their own.
        for ($i = 0; $i < 10; $i++) {
            $headers[] = "Категория {$i}";
            $row[] = 'A';
        }

        $second = array_fill(0, 10, 'B');

        $columns = $this->infer($headers, [$row, $second, $row]);

        $indexed = array_filter($columns, fn (InferredColumn $column) => $column->indexed);

        $this->assertCount(2, $indexed, 'A 150-column file must not get 100 indexes nothing will use.');
    }

    /**
     * Free prose cannot live in an indexable VARCHAR, and truncating it to make it
     * groupable would trade the owner's data for a GROUP BY nobody asked for.
     */
    public function test_a_long_text_column_is_neither_indexed_nor_truncated(): void
    {
        $long = str_repeat('очень подробное описание товара ', 30);

        $columns = $this->infer(['Описание'], [[$long], [$long.'!'], [$long.'?']]);

        $this->assertSame(ColumnType::Text, $columns[0]->type);
        $this->assertTrue($columns[0]->isLongText());
        $this->assertFalse($columns[0]->indexed);
    }

    public function test_it_stops_sampling_at_the_configured_row_count(): void
    {
        config(['agent.bi.ingest.inference_rows' => 5]);

        $inferrer = new SchemaInferrer(['Сумма']);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($inferrer->wantsMore());
            $inferrer->observe(['100']);
        }

        $this->assertFalse(
            $inferrer->wantsMore(),
            'Inference must stop sampling so a 2M-row file is not read twice.'
        );
    }
}
