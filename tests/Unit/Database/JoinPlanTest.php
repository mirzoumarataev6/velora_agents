<?php

namespace Tests\Unit\Database;

use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\Join;
use App\Services\Database\Discovery\JoinPlan;
use InvalidArgumentException;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * The closed-set check, extended to cover joins.
 *
 * The single-table feature's safety came down to one rule: every identifier in
 * the SQL was read off the customer's own catalog by us. A join names three more
 * things — another table, the column pair it matches on, and the columns it
 * contributes — so the rule has to cover all of them. Everything asserted here
 * is something a plan is NOT allowed to say.
 *
 * A stored plan is replayed on every customer question, so this runs before each
 * query rather than only when the owner confirms one: a schema that changed
 * underneath us must fail here, not on the customer's server.
 */
class JoinPlanTest extends TestCase
{
    protected function schema(): DiscoveredSchema
    {
        return DiscoveredSchema::fromDriver(new FakeDatabaseDriver(
            tables: [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'stocks', 'type' => 'table'],
            ],
            schema: [
                'products' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
                ],
                'categories' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                ],
                'stocks' => [
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'quantity', 'type' => 'int', 'numeric' => true],
                ],
            ],
            unique: [
                ['table' => 'products', 'column' => 'id'],
                ['table' => 'categories', 'column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id'],
            ],
        ));
    }

    protected function categoryJoin(array $overrides = []): Join
    {
        return new Join(
            table: $overrides['table'] ?? 'categories',
            alias: $overrides['alias'] ?? 'j0',
            localAlias: $overrides['localAlias'] ?? '',
            localColumn: $overrides['localColumn'] ?? 'category_id',
            foreignColumn: $overrides['foreignColumn'] ?? 'id',
            columns: $overrides['columns'] ?? ['category' => 'name'],
        );
    }

    protected function assertRejected(JoinPlan $plan, string $because): void
    {
        $this->assertFalse($plan->isValid($this->schema(), 'products'), $because);

        $this->expectException(InvalidArgumentException::class);
        $plan->assertValid($this->schema(), 'products');
    }

    public function test_a_plan_built_from_the_schema_is_accepted(): void
    {
        $plan = new JoinPlan([
            $this->categoryJoin(),
            new Join('stocks', 'j1', '', 'id', 'product_id', ['stock' => 'quantity']),
        ]);

        $plan->assertValid($this->schema(), 'products');

        $this->assertTrue($plan->isValid($this->schema(), 'products'));
        $this->assertSame(['categories', 'stocks'], $plan->tables());
    }

    public function test_a_join_naming_a_table_outside_the_schema_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['table' => 'users'])]),
            'A join may not name a table nobody discovered.'
        );
    }

    public function test_a_join_matching_on_a_column_the_local_table_lacks_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['localColumn' => 'secret_id'])]),
            'The local side of a join must be a real column.'
        );
    }

    public function test_a_join_matching_on_a_column_the_joined_table_lacks_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['foreignColumn' => '(SELECT password FROM users)'])]),
            'The far side of a join must be a real column.'
        );
    }

    public function test_a_join_supplying_a_column_the_joined_table_lacks_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['columns' => ['category' => 'title']])]),
            'A join may only contribute columns that exist on it.'
        );
    }

    public function test_a_join_onto_a_non_unique_column_is_refused(): void
    {
        // `products.category_id` is not unique, so matching stocks onto it would
        // return one row per category member.
        $this->assertRejected(
            new JoinPlan([new Join('stocks', 'j0', '', 'category_id', 'quantity', ['stock' => 'quantity'])]),
            'A join must not be able to return a product more than once.'
        );
    }

    public function test_an_alias_this_application_did_not_generate_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['alias' => 'c` ON 1=1 -- '])]),
            'Aliases are ours, and match a fixed pattern.'
        );
    }

    public function test_a_repeated_alias_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(), $this->categoryJoin(['table' => 'stocks', 'localColumn' => 'id', 'foreignColumn' => 'product_id', 'columns' => ['stock' => 'quantity']])]),
            'Two joins may not answer to the same alias.'
        );
    }

    public function test_a_join_attaching_to_a_later_join_is_refused(): void
    {
        // Forward references are how a chain would become a loop.
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['localAlias' => 'j1'])]),
            'A join may only attach to the base table or an earlier join.'
        );
    }

    public function test_a_join_that_supplies_nothing_is_refused(): void
    {
        $this->assertRejected(
            new JoinPlan([$this->categoryJoin(['columns' => []])]),
            'A join with no columns has no reason to exist.'
        );
    }

    public function test_a_plan_deeper_than_the_cap_is_refused(): void
    {
        $plan = new JoinPlan([
            $this->categoryJoin(),
            new Join('stocks', 'j1', '', 'id', 'product_id', ['stock' => 'quantity']),
        ]);

        $this->assertTrue($plan->isValid($this->schema(), 'products', 2));
        $this->assertFalse($plan->isValid($this->schema(), 'products', 1));
    }

    public function test_a_plan_anchored_to_an_undiscovered_table_is_refused(): void
    {
        $plan = new JoinPlan([$this->categoryJoin()]);

        $this->assertFalse($plan->isValid($this->schema(), 'information_schema.tables'));
    }

    public function test_a_stored_plan_round_trips_through_json(): void
    {
        $plan = new JoinPlan([
            $this->categoryJoin(),
            new Join('stocks', 'j1', '', 'id', 'product_id', ['stock' => 'quantity'], declared: false),
        ]);

        $restored = JoinPlan::fromArray(json_decode(json_encode($plan->toArray()), true));

        $this->assertEquals($plan->toArray(), $restored->toArray());
        $this->assertTrue($restored->isValid($this->schema(), 'products'));
        $this->assertTrue($restored->hasInferredKeys());
        $this->assertSame('quantity', $restored->fields()['stock']['column']);
    }

    public function test_a_plan_decoded_from_junk_is_empty_rather_than_malformed(): void
    {
        $plan = JoinPlan::fromArray(['nonsense', 42, ['table' => ['nested']]]);

        $this->assertCount(1, $plan->joins);
        // The one array-shaped entry survives decoding — and is then refused by
        // validation, which is the check that actually matters.
        $this->assertFalse($plan->isValid($this->schema(), 'products'));
    }

    public function test_an_empty_plan_is_valid_and_supplies_nothing(): void
    {
        $plan = new JoinPlan;

        $plan->assertValid($this->schema(), 'products');

        $this->assertTrue($plan->isEmpty());
        $this->assertFalse($plan->supplies('category'));
        $this->assertSame([], $plan->fields());
    }
}
