<?php

namespace Tests\Unit\Bi;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\User;
use App\Services\Bi\Query\Aggregate;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Bi\Query\SortDirection;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Query\TimeGrain;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The door into the query layer, and everything it refuses to let through.
 *
 * A request body, a saved dashboard tile and the AI analyst's function call all
 * arrive here as the same untrusted nested array. Every refusal below carries a
 * classified code with a sentence in all three locales — the test at the bottom
 * asserts that, the same way MalformedFileTest does for ingestion.
 */
class SpecValidatorTest extends TestCase
{
    use RefreshDatabase;

    private BiDataset $dataset;

    private SpecValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SpecValidator;

        $user = User::factory()->create();
        $this->dataset = BiDataset::factory()->create(['user_id' => $user->id]);

        // c0 date, c1 text, c2 decimal, c3 boolean — one of each shape the
        // validator has a rule about.
        $this->addColumn(0, 'Дата', ColumnType::Date, ColumnRole::Time);
        $this->addColumn(1, 'Регион', ColumnType::Text, ColumnRole::Dimension);
        $this->addColumn(2, 'Выручка', ColumnType::Decimal, ColumnRole::Measure);
        $this->addColumn(3, 'Оплачен', ColumnType::Boolean, ColumnRole::Dimension);
    }

    private function addColumn(int $ordinal, string $name, ColumnType $type, ColumnRole $role): BiColumn
    {
        return BiColumn::factory()->at($ordinal)->type($type, $role)->create([
            'bi_dataset_id' => $this->dataset->id,
            'user_id' => $this->dataset->user_id,
            'source_name' => $name,
        ]);
    }

    private function validate(array $input): QuerySpec
    {
        return $this->validator->validate($this->dataset->fresh(), $input);
    }

    private function assertRefused(string $code, array $input): BiQueryException
    {
        try {
            $this->validate($input);
        } catch (BiQueryException $e) {
            $this->assertSame($code, $e->reason, 'Wrong refusal code.');

            return $e;
        }

        $this->fail("Expected the spec to be refused with [{$code}].");
    }

    // --- The happy path ------------------------------------------------------

    public function test_a_column_can_be_named_by_physical_name_or_by_id(): void
    {
        $byName = $this->validate([
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $id = BiColumn::where('bi_dataset_id', $this->dataset->id)->where('physical_name', 'c1')->value('id');

        $byId = $this->validate([
            'dimensions' => [['column' => $id]],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $this->assertSame('c1', $byName->dimensions[0]->column->physical_name);
        $this->assertSame('c1', $byId->dimensions[0]->column->physical_name);
    }

    public function test_aliases_are_dense_and_assigned_by_position(): void
    {
        $spec = $this->validate([
            'dimensions' => [['column' => 'c1'], ['column' => 'c3']],
            'measures' => [
                ['aggregate' => 'sum', 'column' => 'c2'],
                ['aggregate' => 'count'],
            ],
        ]);

        $this->assertSame(['d0', 'd1'], array_map(fn ($d) => $d->alias(), $spec->dimensions));
        $this->assertSame(['m0', 'm1'], array_map(fn ($m) => $m->alias(), $spec->measures));
    }

    /** COUNT is the one aggregate that needs no column. */
    public function test_count_needs_no_column(): void
    {
        $spec = $this->validate(['measures' => [['aggregate' => 'count']]]);

        $this->assertNull($spec->measures[0]->column);
        $this->assertTrue($spec->isScalar());
    }

    /**
     * A date dimension with no grain gets one.
     *
     * Grouping a datetime column by its raw value yields one bucket per row,
     * which is the table again rather than a chart. See SpecValidator::dimension().
     */
    public function test_a_date_dimension_defaults_to_a_month_grain(): void
    {
        $spec = $this->validate([
            'dimensions' => [['column' => 'c0']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $this->assertSame(TimeGrain::Month, $spec->dimensions[0]->grain);
    }

    public function test_a_single_filter_value_may_be_a_scalar(): void
    {
        $spec = $this->validate([
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']],
        ]);

        $this->assertSame(['Душанбе'], $spec->filters[0]->bindings());
    }

    public function test_sorting_by_a_measure_alias_is_accepted(): void
    {
        $spec = $this->validate([
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            'sorts' => [['field' => 'm0', 'direction' => 'desc']],
        ]);

        $this->assertSame('m0', $spec->sorts[0]->alias);
        $this->assertSame(SortDirection::Desc, $spec->sorts[0]->direction);
    }

    // --- Refusals ------------------------------------------------------------

    public function test_a_column_outside_the_dataset_is_simply_not_found(): void
    {
        $this->assertRefused(BiQueryException::UNKNOWN_COLUMN, [
            'dimensions' => [['column' => 'c99']],
        ]);
    }

    /**
     * An identifier the caller invented is refused rather than quoted.
     *
     * The payload is the point: it is not escaped, it is NOT FOUND — the lookup
     * is against a map of this dataset's own columns, so there is no path in
     * which this string becomes part of an identifier.
     */
    public function test_an_injected_identifier_is_refused_as_an_unknown_column(): void
    {
        foreach (['c1`; DROP TABLE users; --', '(SELECT 1)', 'c1 OR 1=1', ''] as $payload) {
            $this->assertRefused(BiQueryException::UNKNOWN_COLUMN, [
                'dimensions' => [['column' => $payload]],
            ]);
        }
    }

    public function test_an_unknown_aggregate_grain_or_direction_is_refused(): void
    {
        $this->assertRefused(BiQueryException::UNKNOWN_OPERATION, [
            'measures' => [['aggregate' => 'median', 'column' => 'c2']],
        ]);

        $this->assertRefused(BiQueryException::UNKNOWN_OPERATION, [
            'dimensions' => [['column' => 'c0', 'grain' => 'fortnight']],
        ]);

        $this->assertRefused(BiQueryException::UNKNOWN_OPERATION, [
            'measures' => [['aggregate' => 'count']],
            'sorts' => [['field' => 'm0', 'direction' => 'sideways']],
        ]);

        $this->assertRefused(BiQueryException::UNKNOWN_OPERATION, [
            'filters' => [['column' => 'c1', 'operator' => 'regex', 'value' => '.*']],
            'measures' => [['aggregate' => 'count']],
        ]);
    }

    public function test_summing_a_text_column_is_refused(): void
    {
        $this->assertRefused(BiQueryException::NOT_NUMERIC, [
            'measures' => [['aggregate' => 'sum', 'column' => 'c1']],
        ]);
    }

    /** MIN and MAX are deliberately NOT numeric-only — see Aggregate. */
    public function test_min_and_max_are_allowed_on_a_date(): void
    {
        $spec = $this->validate(['measures' => [['aggregate' => 'max', 'column' => 'c0']]]);

        $this->assertSame(Aggregate::Max, $spec->measures[0]->aggregate);
        $this->assertSame(ColumnType::Date, $spec->measures[0]->resultType());
    }

    public function test_bucketing_a_non_date_column_is_refused(): void
    {
        $this->assertRefused(BiQueryException::NOT_TEMPORAL, [
            'dimensions' => [['column' => 'c1', 'grain' => 'month']],
        ]);
    }

    public function test_an_ordering_operator_on_an_unordered_column_is_refused(): void
    {
        $this->assertRefused(BiQueryException::UNKNOWN_OPERATION, [
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c3', 'operator' => 'gt', 'value' => true]],
        ]);
    }

    public function test_a_filter_with_the_wrong_number_of_values_is_refused(): void
    {
        $this->assertRefused(BiQueryException::BAD_FILTER_VALUE, [
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c2', 'operator' => 'between', 'values' => [1]]],
        ]);

        // `IN ()` is a syntax error in MySQL and silently false in SQLite.
        $this->assertRefused(BiQueryException::BAD_FILTER_VALUE, [
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c1', 'operator' => 'in', 'values' => []]],
        ]);
    }

    public function test_an_empty_spec_is_refused(): void
    {
        $this->assertRefused(BiQueryException::EMPTY_SPEC, []);
    }

    public function test_sorting_by_something_the_query_does_not_select_is_refused(): void
    {
        $this->assertRefused(BiQueryException::UNSORTABLE, [
            'measures' => [['aggregate' => 'count']],
            'sorts' => [['field' => 'm3', 'direction' => 'asc']],
        ]);

        // Not even by a real column: a sort names an output alias. See Sort.
        $this->assertRefused(BiQueryException::UNSORTABLE, [
            'measures' => [['aggregate' => 'count']],
            'sorts' => [['field' => 'c2', 'direction' => 'asc']],
        ]);
    }

    public function test_too_many_fields_is_refused(): void
    {
        $measures = array_fill(0, QuerySpec::maxFields() + 1, ['aggregate' => 'count']);

        $this->assertRefused(BiQueryException::TOO_MANY_FIELDS, ['measures' => $measures]);
    }

    public function test_a_malformed_field_entry_is_refused_rather_than_coerced(): void
    {
        $this->assertRefused(BiQueryException::UNKNOWN_OPERATION, [
            'dimensions' => ['c1'],
        ]);
    }

    /**
     * Every refusal this class can produce has a sentence in every locale.
     *
     * The same bar MalformedFileTest sets for ingestion, and worth repeating
     * here: a classified code with no translation renders as the raw key, which
     * is how `bi.errors.not_numeric` ends up in front of an owner.
     */
    public function test_every_refusal_code_is_translated_in_all_three_locales(): void
    {
        $reflection = new \ReflectionClass(BiQueryException::class);

        foreach ($reflection->getConstants() as $name => $code) {
            if (! is_string($code)) {
                continue;
            }

            foreach (['ru', 'tg', 'en'] as $locale) {
                $key = 'bi.errors.'.$code;
                $translated = __($key, [], $locale);

                $this->assertNotSame($key, $translated, "BiQueryException::{$name} has no {$locale} sentence.");
            }
        }
    }
}
