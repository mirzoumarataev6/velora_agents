<?php

namespace Tests\Unit\Bi;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Services\Bi\Query\Aggregate;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\Dimension;
use App\Services\Bi\Query\ExternalMySqlQueryCompiler;
use App\Services\Bi\Query\ExternalPostgresQueryCompiler;
use App\Services\Bi\Query\Filter;
use App\Services\Bi\Query\FilterOperator;
use App\Services\Bi\Query\Measure;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Bi\Query\Sort;
use App\Services\Bi\Query\SortDirection;
use App\Services\Bi\Query\TimeGrain;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Tests\TestCase;

class ExternalQueryCompilerTest extends TestCase
{
    private function spec(string $table = 'public.orders', string $measureName = 'revenue'): QuerySpec
    {
        $dataset = new BiDataset(['name' => 'Orders', 'external_table' => $table]);
        $dataset->id = 7;
        $date = new BiColumn(['ordinal' => 0, 'physical_name' => 'c0', 'source_name' => 'ordered_on', 'type' => ColumnType::Date, 'role' => ColumnRole::Time]);
        $date->bi_dataset_id = 7;
        $amount = new BiColumn(['ordinal' => 1, 'physical_name' => 'c1', 'source_name' => $measureName, 'type' => ColumnType::Decimal, 'role' => ColumnRole::Measure]);
        $amount->bi_dataset_id = 7;

        return new QuerySpec($dataset, [new Dimension($date, TimeGrain::Month)], [new Measure(Aggregate::Sum, $amount)],
            [new Filter($date, FilterOperator::GreaterOrEqual, ['2026-01-01'])], [new Sort('m0', SortDirection::Desc)], 10);
    }

    public function test_mysql_uses_only_registered_live_identifiers_and_bound_values(): void
    {
        $compiled = (new ExternalMySqlQueryCompiler)->compile($this->spec());
        $this->assertStringContainsString('FROM `public`.`orders`', $compiled->sql);
        $this->assertStringContainsString('SUM(`revenue`)', $compiled->sql);
        $this->assertStringContainsString('`ordered_on` >= ?', $compiled->sql);
        $this->assertStringNotContainsString('2026-01-01', $compiled->sql);
        $this->assertSame(['2026-01-01'], $compiled->bindings);
    }

    public function test_postgres_uses_its_own_date_and_nulls_last_syntax(): void
    {
        $compiled = (new ExternalPostgresQueryCompiler)->compile($this->spec());
        $this->assertStringContainsString('FROM "public"."orders"', $compiled->sql);
        $this->assertStringContainsString("to_char(\"ordered_on\", 'YYYY-MM-01')", $compiled->sql);
        $this->assertStringContainsString('"m0" DESC NULLS LAST', $compiled->sql);
    }

    public function test_a_tampered_registered_name_is_refused_before_sql(): void
    {
        $this->expectException(BiQueryException::class);
        (new ExternalMySqlQueryCompiler)->compile($this->spec('orders;DROP TABLE users'));
    }

    public function test_a_tampered_column_name_is_refused_before_sql(): void
    {
        $this->expectException(BiQueryException::class);
        (new ExternalPostgresQueryCompiler)->compile($this->spec('orders', 'revenue;DROP'));
    }
}
