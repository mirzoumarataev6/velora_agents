<?php

namespace Tests\Unit\Database;

use App\Exceptions\LlmException;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\DiscoveryResult;
use App\Services\Database\Discovery\SchemaDiscoverer;
use App\Services\Database\Discovery\SuggestionValidator;
use App\Services\Database\Discovery\TableCandidate;
use App\Services\Llm\LlmProviderInterface;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * The optional last step, and the gate it has to get through.
 *
 * The model is allowed to READ table and column names and say what it thinks
 * they mean. It is not a source of names. Everything here is about that line:
 * what it is shown, what happens to an answer that names something unreal, and
 * what happens when it fails entirely.
 */
class SchemaInterpreterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.database.discovery.use_llm' => true]);
    }

    /**
     * Two equally plausible tables and no keys — the ambiguous case, which is
     * the only kind that reaches the model at all.
     */
    protected function driver(): FakeDatabaseDriver
    {
        return new FakeDatabaseDriver(
            tables: [
                ['name' => 'tbl_a', 'type' => 'table'],
                ['name' => 'tbl_b', 'type' => 'table'],
            ],
            rows: [['name' => 'Куртка', 'price' => '4500.00']],
            schema: [
                'tbl_a' => [
                    ['name' => 'f_name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'f_cost', 'type' => 'decimal', 'numeric' => true],
                ],
                'tbl_b' => [
                    ['name' => 'f_name', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'f_cost', 'type' => 'decimal', 'numeric' => true],
                ],
            ],
        );
    }

    protected function schema(): DiscoveredSchema
    {
        return DiscoveredSchema::fromDriver($this->driver());
    }

    protected function answering(string $text, ?callable $capture = null): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($text, $capture) {
            $mock->shouldReceive('generate')
                ->andReturnUsing(function (string $system, array $contents, array $options) use ($text, $capture) {
                    if ($capture !== null) {
                        $capture($system, $contents, $options);
                    }

                    return ['text' => $text, 'input_tokens' => 0, 'output_tokens' => 0, 'finish_reason' => 'stop', 'tool_calls' => []];
                });
        });
    }

    public function test_the_model_is_shown_names_and_types_and_nothing_else(): void
    {
        $captured = null;

        $this->answering('{"table":"tbl_a","mapping":{"name":"f_name","price":"f_cost"}}', function ($system, $contents) use (&$captured) {
            $captured = ['system' => $system, 'payload' => $contents[0]['text'] ?? ''];
        });

        app(SchemaDiscoverer::class)->discover($this->driver());

        $this->assertNotNull($captured);

        // Names and types are there...
        $this->assertStringContainsString('tbl_a', $captured['payload']);
        $this->assertStringContainsString('f_cost', $captured['payload']);
        $this->assertStringContainsString('decimal', $captured['payload']);

        // ...and nothing else is. No row data, no credentials, no host.
        $this->assertStringNotContainsString('Куртка', $captured['payload']);
        $this->assertStringNotContainsString('4500', $captured['payload']);
        $this->assertStringNotContainsString('secret', $captured['payload']);
        $this->assertStringNotContainsString('db.example.com', $captured['payload']);

        // And it is asked to interpret, never to write SQL.
        $this->assertStringNotContainsStringIgnoringCase('SELECT', $captured['system']);
    }

    public function test_a_valid_suggestion_is_adopted_but_never_sold_as_certain(): void
    {
        $this->answering('{"table":"tbl_b","mapping":{"name":"f_name","price":"f_cost"}}');

        $result = app(SchemaDiscoverer::class)->discover($this->driver());

        $this->assertSame(DiscoveryResult::LLM_ASSISTED, $result->method);
        $this->assertSame('tbl_b', $result->best()->table);
        $this->assertSame('f_name', $result->best()->roles->column('name'));

        // It was only asked because the facts did not settle it, so its answer
        // cannot be the reason to claim they now do.
        $this->assertSame(TableCandidate::LOW, $result->best()->confidence);
        $this->assertContains('interpreted_by_model', array_column($result->best()->reasons, 'code'));

        // The other candidate is still offered: the owner still chooses.
        $this->assertNotNull($result->proposalFor('tbl_a'));
    }

    public function test_a_suggestion_naming_a_table_that_does_not_exist_is_discarded(): void
    {
        $this->answering('{"table":"secret_admin_table","mapping":{"name":"f_name"}}');

        $result = app(SchemaDiscoverer::class)->discover($this->driver());

        // Back to the deterministic ranking, as though it had never answered.
        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
        $this->assertNull($result->proposalFor('secret_admin_table'));
        $this->assertNotNull($result->best());
    }

    public function test_a_suggestion_naming_a_column_that_does_not_exist_is_discarded_whole(): void
    {
        // One good column, one invented one.
        $this->answering('{"table":"tbl_a","mapping":{"name":"f_name","price":"password_hash"}}');

        $result = app(SchemaDiscoverer::class)->discover($this->driver());

        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);

        // NOT repaired by keeping the half that checked out: a suggestion we had
        // to correct is one we have no reason to trust the rest of.
        $this->assertNotContains('interpreted_by_model', array_column($result->best()->reasons, 'code'));
        $this->assertNotContains('password_hash', $result->best()->roles->toMapping());
    }

    public function test_a_suggestion_that_is_not_json_is_discarded(): void
    {
        $this->answering('I think the products are probably in tbl_a, but I would check both.');

        $this->assertSame(
            DiscoveryResult::DETERMINISTIC,
            app(SchemaDiscoverer::class)->discover($this->driver())->method,
        );
    }

    public function test_a_provider_failure_leaves_discovery_working(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new LlmException('upstream is down'));
        });

        $result = app(SchemaDiscoverer::class)->discover($this->driver());

        // The deterministic pass IS the answer; this was only ever a tie-break
        // on top of it.
        $this->assertSame(DiscoveryResult::DETERMINISTIC, $result->method);
        $this->assertNotNull($result->best());
    }

    public function test_the_validator_refuses_anything_the_schema_cannot_confirm(): void
    {
        $validator = new SuggestionValidator;
        $schema = $this->schema();
        $allowed = ['tbl_a', 'tbl_b'];

        // The one shape that passes.
        $this->assertNotNull($validator->validate($schema, $allowed, [
            'table' => 'tbl_a',
            'mapping' => ['name' => 'f_name', 'price' => 'f_cost'],
        ]));

        // A real table that was not on the shortlist: it was asked to choose
        // between these, not to go looking.
        $this->assertNull($validator->validate($schema, ['tbl_b'], [
            'table' => 'tbl_a',
            'mapping' => ['name' => 'f_name'],
        ]));

        // A field outside the catalog vocabulary.
        $this->assertNull($validator->validate($schema, $allowed, [
            'table' => 'tbl_a',
            'mapping' => ['name' => 'f_name', 'password' => 'f_cost'],
        ]));

        // No product name: the same rule the manual mapping screen enforces.
        $this->assertNull($validator->validate($schema, $allowed, [
            'table' => 'tbl_a',
            'mapping' => ['price' => 'f_cost'],
        ]));

        // Shapes that are not mappings at all.
        $this->assertNull($validator->validate($schema, $allowed, ['table' => 'tbl_a', 'mapping' => []]));
        $this->assertNull($validator->validate($schema, $allowed, ['table' => 'tbl_a']));
        $this->assertNull($validator->validate($schema, $allowed, []));
        $this->assertNull($validator->validate($schema, $allowed, [
            'table' => 'tbl_a',
            'mapping' => ['name' => ['nested']],
        ]));
    }

    public function test_column_types_come_from_the_schema_not_from_the_model(): void
    {
        $validator = new SuggestionValidator;

        $roles = $validator->toRoles($this->schema(), 'tbl_a', ['name' => 'f_name', 'price' => 'f_cost']);

        // Whatever the model said about them, `f_cost` is numeric because
        // information_schema says so.
        $this->assertTrue($roles->isNumeric('price'));
        $this->assertFalse($roles->isNumeric('name'));
    }
}
