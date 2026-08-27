<?php

namespace Tests\Unit\Database;

use App\Services\Database\Discovery\ColumnRoleResolver;
use App\Services\Database\Discovery\DiscoveredSchema;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Reading one table's columns as product fields.
 *
 * This shares the uploaded catalog's vocabulary but adds the thing a spreadsheet
 * never had: types. What is asserted here is the cases where the type is what
 * decides — the ones a header-only matcher gets wrong.
 */
class ColumnRoleResolverTest extends TestCase
{
    protected ColumnRoleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ColumnRoleResolver;
    }

    /**
     * @param  array<string, string>  $columns  column => type
     */
    protected function table(array $columns, string $table = 'products'): DiscoveredSchema
    {
        $numericTypes = ['int', 'bigint', 'decimal', 'float', 'double', 'numeric'];

        $described = [];
        foreach ($columns as $name => $type) {
            $described[] = ['name' => $name, 'type' => $type, 'numeric' => in_array($type, $numericTypes, true)];
        }

        return DiscoveredSchema::fromDriver(new FakeDatabaseDriver(
            tables: [['name' => $table, 'type' => 'table']],
            schema: [$table => $described],
        ));
    }

    public function test_the_catalog_vocabulary_is_shared_across_all_three_languages(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'Название' => 'varchar',
            'Цена' => 'decimal',
            'Остаток' => 'int',
            'Категория' => 'varchar',
        ]), 'products');

        $this->assertSame('Название', $roles->column('name'));
        $this->assertSame('Цена', $roles->column('price'));
        $this->assertSame('Остаток', $roles->column('stock'));
        $this->assertSame('Категория', $roles->column('category'));
    }

    public function test_a_numeric_column_is_never_read_as_the_product_name(): void
    {
        // `product_id` matches the "product" alias on name, and is an integer. A
        // product name is text; matching a word is not the same as meaning it.
        $roles = $this->resolver->resolve($this->table([
            'product_id' => 'int',
            'title' => 'varchar',
        ]), 'products');

        $this->assertSame('title', $roles->column('name'));
        $this->assertNotSame('product_id', $roles->column('name'));
    }

    public function test_a_table_with_only_a_numeric_name_candidate_gets_no_name_at_all(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'product_id' => 'int',
            'price' => 'decimal',
        ]), 'products');

        // Refused outright rather than accepted for want of anything better —
        // the scorer reads this as "cannot name a product from here".
        $this->assertFalse($roles->hasName());
    }

    public function test_the_numeric_price_wins_over_a_textual_one(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'name' => 'varchar',
            'price_text' => 'varchar',
            'price' => 'decimal',
        ]), 'products');

        // The search can only compare a number against "cheaper than 500".
        $this->assertSame('price', $roles->column('price'));
        $this->assertTrue($roles->hasNumericPrice());
    }

    public function test_a_price_stored_as_text_is_still_mapped_but_not_filterable(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'name' => 'varchar',
            'Цена' => 'varchar',
        ]), 'products');

        // It still reaches the customer; it just cannot answer a range question,
        // which LiveProductSource already knows to drop.
        $this->assertSame('Цена', $roles->column('price'));
        $this->assertFalse($roles->hasNumericPrice());
    }

    public function test_a_non_numeric_stock_column_is_kept(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'name' => 'varchar',
            'наличие' => 'varchar',
        ]), 'products');

        // A shop whose stock column holds "в наличии" still has its value read
        // out to the customer — only the "> 0" filter is dropped.
        $this->assertSame('наличие', $roles->column('stock'));
        $this->assertFalse($roles->isNumeric('stock'));
    }

    public function test_an_exact_column_name_wins_before_any_loose_match(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'Название' => 'varchar',
            'Цена закупки' => 'decimal',
            'Цена' => 'decimal',
        ]), 'products');

        // Otherwise the agent quotes wholesale prices to retail customers.
        $this->assertSame('Цена', $roles->column('price'));
    }

    public function test_every_field_takes_at_most_one_column_and_every_column_at_most_one_field(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'name' => 'varchar',
            'title' => 'varchar',
            'price' => 'decimal',
            'cost' => 'decimal',
        ]), 'products');

        $mapping = $roles->toMapping();

        $this->assertSame(count($mapping), count(array_unique($mapping)));
        $this->assertSame('name', $roles->column('name'));
        $this->assertSame('price', $roles->column('price'));
    }

    public function test_a_key_column_is_not_offered_as_the_article_number(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'id' => 'int',
            'name' => 'varchar',
            'category_id' => 'int',
        ]), 'products');

        // Essentially every table has a surrogate `id` and most have foreign
        // keys beside it. Offering one would have the agent reading internal row
        // numbers out to customers — or a product's article number reported as
        // the id of its category.
        $this->assertFalse($roles->has('sku'));
    }

    public function test_a_real_article_number_is_still_recognized(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'id' => 'int',
            'name' => 'varchar',
            'артикул' => 'varchar',
        ]), 'products');

        $this->assertSame('артикул', $roles->column('sku'));
    }

    public function test_a_table_with_nothing_recognizable_yields_no_roles(): void
    {
        $roles = $this->resolver->resolve($this->table([
            'f1' => 'varchar',
            'f2' => 'int',
        ]), 'products');

        $this->assertTrue($roles->isEmpty());
        $this->assertFalse($roles->hasName());
        $this->assertSame([], $roles->toMapping());
    }

    public function test_a_table_that_is_not_in_the_schema_yields_no_roles(): void
    {
        $roles = $this->resolver->resolve($this->table(['name' => 'varchar']), 'something_else');

        $this->assertTrue($roles->isEmpty());
    }

    public function test_it_reports_whether_another_table_could_supply_a_field(): void
    {
        $schema = DiscoveredSchema::fromDriver(new FakeDatabaseDriver(
            tables: [['name' => 'oc_product', 'type' => 'table'], ['name' => 'oc_product_description', 'type' => 'table']],
            schema: [
                'oc_product' => [['name' => 'price', 'type' => 'decimal', 'numeric' => true]],
                'oc_product_description' => [['name' => 'name', 'type' => 'varchar', 'numeric' => false]],
            ],
        ));

        $this->assertTrue($this->resolver->supplies($schema, 'oc_product_description', 'name'));
        $this->assertFalse($this->resolver->supplies($schema, 'oc_product', 'name'));
    }
}
