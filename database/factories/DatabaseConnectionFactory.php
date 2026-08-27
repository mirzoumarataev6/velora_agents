<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    protected $model = DatabaseConnection::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'engine' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'readonly',
            'password' => 'secret',
            'ssl' => false,
            'status' => DatabaseConnection::STATUS_UNVERIFIED,
        ];
    }

    /**
     * A connection that has been verified, discovered and mapped — the state in
     * which the agent will actually use it.
     */
    public function configured(): static
    {
        return $this->state(fn () => [
            'status' => DatabaseConnection::STATUS_READY,
            'last_tested_at' => now(),
            'table_name' => 'products',
            'table_type' => 'table',
            'discovered_at' => now(),
            'schema_columns' => [
                ['name' => 'title', 'type' => 'varchar', 'numeric' => false],
                ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                ['name' => 'qty', 'type' => 'int', 'numeric' => true],
                ['name' => 'cat', 'type' => 'varchar', 'numeric' => false],
                ['name' => 'descr', 'type' => 'text', 'numeric' => false],
                ['name' => 'article', 'type' => 'varchar', 'numeric' => false],
            ],
            'mapping' => [
                'name' => 'title',
                'price' => 'price',
                'stock' => 'qty',
                'category' => 'cat',
                'description' => 'descr',
                'sku' => 'article',
            ],
        ]);
    }

    /**
     * A connection whose catalog is spread across three tables: the products
     * themselves, their category names, and their stock levels.
     *
     * The snapshot is the closed set the joined query validates against, so it
     * carries the unique keys too — without them the joins are refused, which is
     * exactly the behaviour some of the tests are there to prove.
     */
    public function joined(): static
    {
        return $this->configured()->state(fn () => [
            'table_name' => 'products',
            'schema_columns' => [
                ['name' => 'id', 'type' => 'int', 'numeric' => true],
                ['name' => 'title', 'type' => 'varchar', 'numeric' => false],
                ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
            ],
            'mapping' => ['name' => 'title', 'price' => 'price'],
            'schema_snapshot' => [
                'tables' => [
                    ['name' => 'products', 'type' => 'table', 'columns' => [
                        ['name' => 'id', 'type' => 'int', 'numeric' => true],
                        ['name' => 'title', 'type' => 'varchar', 'numeric' => false],
                        ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                        ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
                    ]],
                    ['name' => 'categories', 'type' => 'table', 'columns' => [
                        ['name' => 'id', 'type' => 'int', 'numeric' => true],
                        ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                    ]],
                    ['name' => 'stocks', 'type' => 'table', 'columns' => [
                        ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                        ['name' => 'quantity', 'type' => 'int', 'numeric' => true],
                    ]],
                ],
                'foreign_keys' => [
                    ['table' => 'products', 'column' => 'category_id', 'foreign_table' => 'categories', 'foreign_column' => 'id', 'declared' => true],
                    ['table' => 'stocks', 'column' => 'product_id', 'foreign_table' => 'products', 'foreign_column' => 'id', 'declared' => true],
                ],
                'row_counts' => ['products' => 400],
                'unique_columns' => ['products.id', 'categories.id', 'stocks.product_id'],
            ],
            'joins' => [
                ['table' => 'categories', 'alias' => 'j0', 'local_alias' => '', 'local_column' => 'category_id', 'foreign_column' => 'id', 'columns' => ['category' => 'name'], 'declared' => true],
                ['table' => 'stocks', 'alias' => 'j1', 'local_alias' => '', 'local_column' => 'id', 'foreign_column' => 'product_id', 'columns' => ['stock' => 'quantity'], 'declared' => true],
            ],
            'discovery_confidence' => 'high',
            'discovery_method' => 'automatic',
        ]);
    }
}
