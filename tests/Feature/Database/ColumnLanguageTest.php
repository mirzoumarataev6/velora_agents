<?php

namespace Tests\Feature\Database;

use App\Services\Database\Discovery\ColumnRoles;
use App\Services\Database\Discovery\DiscoveryResult;
use App\Services\Database\Discovery\SchemaDiscoverer;
use App\Services\Database\Discovery\TableCandidate;
use App\Services\Llm\LlmProviderInterface;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Reading column names that are not written in any of the vocabulary's own
 * languages, in two layers.
 *
 *   Layer 1 — TRANSLITERATION. Deterministic, always on, no model. `nazvanie`
 *             and `cena` are Russian words typed on a latin keyboard; they are
 *             turned back into Cyrillic and looked up in the vocabulary that
 *             already exists.
 *   Layer 2 — INTERPRETATION. A model, asked about specific columns of one
 *             table, and only when layer 1 left a REQUIRED field unread. An
 *             Uzbek `atalishi` is not a spelling of anything we carry, so no
 *             amount of transliterating reaches it.
 *
 * The ordering between them is the property most of these tests defend: layer 1
 * never costs a model call, and neither layer can override something the
 * vocabulary read directly.
 */
class ColumnLanguageTest extends TestCase
{
    /**
     * A one-table shop. Column specs are [name => type].
     *
     * @param  array<string, string>  $columns
     */
    protected function driver(string $table, array $columns): FakeDatabaseDriver
    {
        $numericTypes = ['int', 'bigint', 'decimal', 'float', 'double'];

        $described = [];

        foreach ($columns as $name => $type) {
            $described[] = [
                'name' => $name,
                'type' => $type,
                'numeric' => in_array($type, $numericTypes, true),
            ];
        }

        return new FakeDatabaseDriver(
            tables: [['name' => $table, 'type' => 'table']],
            schema: [$table => $described],
            unique: [['table' => $table, 'column' => 'id']],
        );
    }

    /** A model that must never be asked anything. */
    protected function silentModel(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldNotReceive('generate');
        });
    }

    /** A model that answers one question with this exact JSON. */
    protected function modelAnswering(string $json): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($json) {
            $mock->shouldReceive('generate')->andReturn([
                'text' => $json,
                'input_tokens' => 40,
                'output_tokens' => 12,
                'finish_reason' => 'STOP',
            ]);
        });
    }

    protected function discover(FakeDatabaseDriver $driver): DiscoveryResult
    {
        return app(SchemaDiscoverer::class)->discover($driver);
    }

    // ---- Layer 1 -----------------------------------------------------------

    public function test_transliterated_russian_columns_are_read_without_a_model(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->silentModel();

        $result = $this->discover($this->driver('tovary', [
            'id' => 'int',
            'nazvanie' => 'varchar',
            'cena' => 'decimal',
            'ostatok' => 'int',
            'kategoriya' => 'varchar',
        ]));

        $best = $result->best();

        $this->assertNotNull($best);
        $this->assertSame('tovary', $best->table);

        // Every field read, and read from the right column.
        $this->assertSame('nazvanie', $best->roles->column('name'));
        $this->assertSame('cena', $best->roles->column('price'));
        $this->assertSame('ostatok', $best->roles->column('stock'));
        $this->assertSame('kategoriya', $best->roles->column('category'));

        // The price is still judged by its TYPE, not by the fact that we had to
        // transliterate its name to recognize it.
        $this->assertTrue($best->roles->hasNumericPrice());

        // And the whole thing was deterministic: the model was never asked, and
        // the mock would have failed the test if it had been.
        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
    }

    public function test_a_transliterated_reading_is_reported_so_the_owner_can_check_it(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->silentModel();

        $best = $this->discover($this->driver('tovary', [
            'id' => 'int',
            'nazvanie' => 'varchar',
            'cena' => 'decimal',
        ]))->best();

        $reason = collect($best->reasons)->firstWhere('code', 'transliterated_columns');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('nazvanie', $reason['params']['columns']);
        $this->assertStringContainsString('cena', $reason['params']['columns']);

        // The provenance travels with the mapping, not just with the sentence.
        $this->assertSame(ColumnRoles::TRANSLITERATED, $best->roles->source('name'));
    }

    public function test_a_direct_match_always_beats_a_transliterated_one(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->silentModel();

        $best = $this->discover($this->driver('products', [
            'id' => 'int',
            // Deliberately first, so column order cannot be what decides it.
            'nazvanie' => 'varchar',
            'name' => 'varchar',
            'cena' => 'decimal',
            'price' => 'decimal',
        ]))->best();

        $this->assertSame('name', $best->roles->column('name'));
        $this->assertSame('price', $best->roles->column('price'));
        $this->assertSame(ColumnRoles::DIRECT, $best->roles->source('name'));
        $this->assertSame(ColumnRoles::DIRECT, $best->roles->source('price'));
    }

    public function test_a_latin_word_that_merely_looks_like_a_cyrillic_one_cannot_take_a_claimed_column(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->silentModel();

        // `tip` transliterates to "тип", which the vocabulary knows as a
        // category — but `type` is that word outright, and the direct reading
        // has to win.
        $best = $this->discover($this->driver('products', [
            'id' => 'int',
            'name' => 'varchar',
            'price' => 'decimal',
            'tip' => 'varchar',
            'type' => 'varchar',
        ]))->best();

        $this->assertSame('type', $best->roles->column('category'));
    }

    // ---- Layer 2 -----------------------------------------------------------

    /** An Uzbek catalog: none of these words is in any vocabulary we carry. */
    protected function uzbekShop(): FakeDatabaseDriver
    {
        return $this->driver('mahsulotlar', [
            'id' => 'int',
            'atalishi' => 'varchar',
            'qiymati' => 'decimal',
            'qoldiq' => 'int',
        ]);
    }

    public function test_a_schema_in_an_unsupported_language_is_resolved_by_the_model(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->modelAnswering('{"mapping": {"name": "atalishi", "price": "qiymati"}}');

        $result = $this->discover($this->uzbekShop());
        $best = $result->best();

        $this->assertNotNull($best);
        $this->assertSame('mahsulotlar', $best->table);
        $this->assertSame('atalishi', $best->roles->column('name'));
        $this->assertSame('qiymati', $best->roles->column('price'));

        $this->assertSame(DiscoveryResult::LLM_ASSISTED, $result->method);
    }

    public function test_what_the_model_read_is_marked_lower_confidence_and_named_to_the_owner(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->modelAnswering('{"mapping": {"name": "atalishi", "price": "qiymati"}}');

        $best = $this->discover($this->uzbekShop())->best();

        // A reading nothing in the schema confirms is never sold as certain.
        $this->assertSame(TableCandidate::LOW, $best->confidence);
        $this->assertSame(ColumnRoles::INTERPRETED, $best->roles->source('name'));

        // And the owner is told which column, in the sentence they will read:
        // "«qiymati» определили как «цена» автоматически — проверьте".
        $reasons = collect($best->reasons)->where('code', 'interpreted_column');

        $this->assertCount(2, $reasons);
        $this->assertSame(
            ['atalishi', 'qiymati'],
            $reasons->pluck('params.column')->sort()->values()->all(),
        );
        $this->assertSame(
            ['name', 'price'],
            $reasons->pluck('params.field')->sort()->values()->all(),
        );
    }

    public function test_an_answer_naming_a_column_that_does_not_exist_is_refused_whole(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        // `nomi` is not a column of this table. The price half is perfectly
        // good, and it goes in the bin with the rest of the answer.
        $this->modelAnswering('{"mapping": {"name": "nomi", "price": "qiymati"}}');

        $result = $this->discover($this->uzbekShop());

        $this->assertTrue($result->isEmpty());
        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
    }

    public function test_an_answer_giving_a_field_a_type_it_cannot_have_is_refused(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        // A numeric column is not a product name, however sure the model is.
        $this->modelAnswering('{"mapping": {"name": "qoldiq", "price": "qiymati"}}');

        $this->assertTrue($this->discover($this->uzbekShop())->isEmpty());
    }

    public function test_an_answer_about_a_field_that_was_not_asked_about_is_refused(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        // Only `name` and `price` were unreadable. An answer that re-reads the
        // rest of the table is answering a question we did not ask.
        $this->modelAnswering('{"mapping": {"name": "atalishi", "price": "qiymati", "stock": "qoldiq"}}');

        $this->assertTrue($this->discover($this->uzbekShop())->isEmpty());
    }

    public function test_an_incomplete_answer_leaves_the_table_unoffered(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        // The model may COMPLETE a candidate. It may not lower the bar one has
        // to clear: without a price this table would not have qualified had we
        // read its columns ourselves.
        $this->modelAnswering('{"mapping": {"name": "atalishi"}}');

        $this->assertTrue($this->discover($this->uzbekShop())->isEmpty());
    }

    public function test_the_fallback_can_be_switched_off(): void
    {
        config(['agent.database.discovery.use_llm' => false]);
        $this->silentModel();

        // Deterministic layers only: the table stays unoffered and the owner is
        // pointed at the manual panel, exactly as before this existed.
        $this->assertTrue($this->discover($this->uzbekShop())->isEmpty());
    }

    public function test_a_table_that_is_not_a_catalog_is_never_handed_to_the_model(): void
    {
        config(['agent.database.discovery.use_llm' => true]);
        $this->silentModel();

        // Unreadable columns are not the reason `migrations` fails, so asking
        // about them would be spending a call on a question with no answer.
        $result = $this->discover($this->driver('migrations', [
            'id' => 'int',
            'migration' => 'varchar',
            'batch' => 'int',
        ]));

        $this->assertTrue($result->isEmpty());
    }
}
