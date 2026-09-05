<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\User;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function csv(string $content, string $name = 'catalog.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * Analyze then import, using the mapping the analyze step suggested.
     *
     * @param  array<string, mixed>|null  $mapping  Overrides the suggestion.
     * @return array{analyze: TestResponse, import: TestResponse}
     */
    protected function upload(User $user, Agent $agent, string $csv, ?array $mapping = null): array
    {
        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv($csv)],
            ['Accept' => 'application/json'],
        );

        $analyze->assertOk();

        $import = $this->actingAs($user)->postJson(route('agents.catalog.import', $agent), [
            'token' => $analyze->json('token'),
            'mapping' => $mapping ?? $analyze->json('mapping'),
        ]);

        return ['analyze' => $analyze, 'import' => $import];
    }

    public function test_analyze_detects_columns_and_suggests_a_mapping(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Название;Цена;Остаток;Категория\nКуртка;1200;5;Верхняя одежда\n")],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('columns', ['Название', 'Цена', 'Остаток', 'Категория'])
            ->assertJsonPath('mapping.name', 0)
            ->assertJsonPath('mapping.price', 1)
            ->assertJsonPath('mapping.stock', 2)
            ->assertJsonPath('mapping.category', 3)
            ->assertJsonPath('row_count', 1);

        // Analyze must not write anything — the mapping is not confirmed yet.
        $this->assertDatabaseCount('dataset_products', 0);
        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_analyze_suggests_english_headers_too(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Product name,Price,Qty,SKU,Link\nJacket,1200,5,AB-1,https://shop.test/1\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('mapping.name', 0)
            ->assertJsonPath('mapping.price', 1)
            ->assertJsonPath('mapping.stock', 2)
            ->assertJsonPath('mapping.sku', 3)
            ->assertJsonPath('mapping.url', 4);
    }

    public function test_analyze_suggests_transliterated_headers_too(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        // The shape a CMS or hosting-panel export actually has: Russian words
        // typed on a latin keyboard. They are read by transliterating them back
        // into the vocabulary's own alphabet rather than by listing every latin
        // spelling of every word — see Transliterator.
        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("nazvanie;cena;ostatok;kategoriya;artikul\nКуртка;1200;5;Верхняя одежда;AB-1\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('mapping.name', 0)
            ->assertJsonPath('mapping.price', 1)
            ->assertJsonPath('mapping.stock', 2)
            ->assertJsonPath('mapping.category', 3)
            ->assertJsonPath('mapping.sku', 4);
    }

    public function test_analyze_reads_a_tajik_header_row(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        // `nom` and `narkh` are in the vocabulary in Cyrillic; the latin
        // spellings come free with the transliteration, which is the point of
        // doing it this way rather than adding aliases per language.
        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("nom;narkh;miqdor\nКуртка;1200;5\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('mapping.name', 0)
            ->assertJsonPath('mapping.price', 1)
            ->assertJsonPath('mapping.stock', 2);
    }

    public function test_a_real_header_beats_a_transliterated_reading_of_another_one(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        // "cena" reads as "цена", but "Цена" IS that word. A transliterated
        // guess must never take a column a real word already claimed — the
        // latin column here is the wholesale one.
        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Название;cena;Цена\nКуртка;600;1200\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('mapping.name', 0)
            ->assertJsonPath('mapping.price', 2);
    }

    /**
     * The reason the mapping step exists: an export whose first price-ish column
     * is the wholesale price must not silently become THE price.
     */
    public function test_an_exact_header_wins_its_column_over_a_loose_match(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Название;Цена закупки;Цена\nКуртка;600;1200\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('mapping.price', 2);
    }

    /**
     * The file behind the bug report. Its columns are not the ones the mapper
     * was written around: there is no Артикул and no Остаток, the product name
     * is headed "Модель", and three of the seven columns (Процессор, ОЗУ,
     * Память) are attributes that belong to laptops and to nothing else.
     *
     * All three failure modes are asserted here: the name must be found, the
     * fields the file does not have must not be invented, and the attributes
     * must be kept rather than dropped.
     */
    public function test_analyze_maps_a_file_whose_columns_are_not_the_usual_ones(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv(
                "№;Модель;Категория;Процессор;ОЗУ;Память;Цена (сомони)\n"
                ."1;Lenovo LOQ 15;Ноутбук (игровой);Core i5-12450H;16 ГБ;512 ГБ SSD;14 999\n"
                ."2;Acer Aspire 3;Ноутбук (офисный);Core i3;8 ГБ;256 ГБ SSD;6 200\n"
            )],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('mapping.name', 1)
            ->assertJsonPath('mapping.category', 2)
            ->assertJsonPath('mapping.price', 6)
            // The attributes are kept as extras, under the file's own headers.
            ->assertJsonPath('mapping.extra', [3, 4, 5]);

        // Nothing is claimed for a field the file has no column for. A suggested
        // "Артикул ← ОЗУ" is worse than no suggestion: the owner confirms it and
        // the agent starts quoting memory sizes as article numbers.
        $mapping = $response->json('mapping');
        $this->assertArrayNotHasKey('sku', $mapping);
        $this->assertArrayNotHasKey('stock', $mapping);
        $this->assertArrayNotHasKey('description', $mapping);
        $this->assertArrayNotHasKey('url', $mapping);

        // The row-number column is neither a field nor an attribute.
        $this->assertNotContains(0, $mapping['extra']);
    }

    /**
     * The name is the one field an import cannot proceed without, so when no
     * header claims it the sampled values are allowed to.
     */
    public function test_a_name_column_is_found_from_its_values_when_its_header_is_unknown(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Код;Позиция;Цена\n1;Куртка зимняя;1200\n2;Шапка вязаная;450\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            // "Позиция" is in no vocabulary; it holds text that differs per row,
            // which the numeric "Код" column does not.
            ->assertJsonPath('mapping.name', 1)
            ->assertJsonPath('mapping.price', 2);
    }

    public function test_extra_attributes_survive_the_import_and_are_searchable(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent,
            "Модель;Категория;ОЗУ;Цена (сомони)\nLenovo LOQ 15;Ноутбук (игровой);16 ГБ;14 999\n",
        )['import']->assertOk();

        $product = DatasetProduct::sole();

        $this->assertSame('Lenovo LOQ 15', $product->name);
        $this->assertSame('14999.00', $product->price);
        $this->assertSame(['ОЗУ' => '16 ГБ'], $product->extra);

        // The catalog is asked about in the customer's words, not the file's:
        // the category and the attributes have to be part of what is matched.
        $this->assertStringContainsString('игровой', $product->search_text);
        $this->assertStringContainsString('озу 16 гб', $product->search_text);
    }

    /**
     * The whole reported bug in one pass: the file is uploaded with the mapping
     * the app suggested for it, and then asked the question the customer asked.
     * Every earlier test here covers one link of that chain; this is the chain.
     */
    public function test_the_uploaded_catalog_answers_the_question_the_customer_asked(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent,
            "№;Модель;Категория;Процессор;ОЗУ;Память;Цена (сомони)\n"
            ."1;Lenovo LOQ 15;Ноутбук (игровой);Core i5-12450H;16 ГБ;512 ГБ SSD;14 999\n"
            ."2;HP Victus 16;Ноутбук (игровой);Ryzen 7;16 ГБ;1 ТБ SSD;18 500\n"
            ."3;Acer Aspire 3;Ноутбук (офисный);Core i3;8 ГБ;256 ГБ SSD;6 200\n",
        )['import']->assertOk();

        // "игровые ноутбуки до 15000 сомони" as the model would fill it in.
        $result = app(ProductSearchService::class)->search($agent, [
            'query' => 'игровые ноутбуки',
            'max_price' => 15000,
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Lenovo LOQ 15', $result['products'][0]['name']);
        $this->assertSame('14999', $result['products'][0]['price']);
        // The attributes travel with it, so the model can answer the follow-up
        // about the processor without another upload having been needed.
        $this->assertSame('Core i5-12450H', $result['products'][0]['details']['Процессор']);
    }

    public function test_import_stores_typed_products_scoped_to_the_agent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $result = $this->upload($user, $agent,
            "Название;Цена;Остаток;Категория;Описание;Артикул\n"
            ."Куртка зимняя;1 200,50 ₽;5;Верхняя одежда;Тёплая куртка;AB-1\n"
            ."Шапка;350;нет;Аксессуары;;AB-2\n"
        );

        $result['import']->assertOk()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('dataset.row_count', 2);

        $jacket = DatasetProduct::where('name', 'Куртка зимняя')->sole();
        $this->assertSame($agent->id, $jacket->agent_id);
        $this->assertSame('1200.50', $jacket->price);
        $this->assertSame(5, $jacket->stock);
        $this->assertSame('Верхняя одежда', $jacket->category);
        $this->assertSame('AB-1', $jacket->sku);

        // "нет" in a stock column is an answer, not a missing value.
        $this->assertSame(0, DatasetProduct::where('name', 'Шапка')->sole()->stock);
    }

    public function test_import_keeps_unmapped_columns_out_and_extra_columns_in(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent,
            "Название;Цена;Цвет;Внутренний код\nКуртка;1200;синий;X-9\n",
            ['name' => 0, 'price' => 1, 'extra' => [2]],
        )['import']->assertOk();

        $product = DatasetProduct::sole();

        $this->assertSame(['Цвет' => 'синий'], $product->extra);
        // The unmapped column is ignored entirely, not swept into extra.
        $this->assertStringNotContainsString('X-9', json_encode($product->extra));
    }

    public function test_malformed_rows_are_skipped_and_reported_rather_than_failing_the_import(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $result = $this->upload($user, $agent,
            "Название;Цена;Остаток\n"
            ."Куртка;1200;5\n"
            .";900;3\n"                  // has data, no name — a real problem
            ."Шапка;не указана;\n"       // unparseable price — kept, price null
            ."Ботинки;-50;abc\n"         // negative price and junk stock — kept, both null
        );

        $result['import']->assertOk()
            ->assertJsonPath('imported', 3)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('skipped_reasons.missing_name', 1);

        $this->assertNull(DatasetProduct::where('name', 'Шапка')->sole()->price);

        $boots = DatasetProduct::where('name', 'Ботинки')->sole();
        $this->assertNull($boots->price);
        $this->assertNull($boots->stock);
    }

    public function test_a_mapping_without_a_name_column_is_rejected_and_imports_nothing(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent, "Название;Цена\nКуртка;1200\n", ['price' => 1])['import']
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.catalog.errors.missing_name'));

        $this->assertDatabaseCount('dataset_products', 0);
    }

    public function test_reupload_replaces_the_existing_dataset(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent, "Название;Цена\nСтарый товар;100\nВторой старый;200\n")['import']->assertOk();
        $this->assertSame(2, DatasetProduct::where('agent_id', $agent->id)->count());

        $this->upload($user, $agent, "Название;Цена\nНовый товар;300\n")['import']
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('dataset.row_count', 1);

        // Replace, not merge: the old products are gone, and there is still
        // exactly one dataset row for the agent.
        $this->assertSame(1, DatasetProduct::where('agent_id', $agent->id)->count());
        $this->assertSame('Новый товар', DatasetProduct::where('agent_id', $agent->id)->sole()->name);
        $this->assertSame(1, Dataset::where('agent_id', $agent->id)->count());
    }

    public function test_a_failed_reimport_leaves_the_previous_catalog_intact(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent, "Название;Цена\nСтарый товар;100\n")['import']->assertOk();

        // A file whose only rows have no name under the confirmed mapping.
        $this->upload($user, $agent, "Название;Цена\n;100\n;200\n", ['name' => 0, 'price' => 1])['import']
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.catalog.errors.no_valid_rows'));

        $this->assertSame('Старый товар', DatasetProduct::where('agent_id', $agent->id)->sole()->name);
    }

    public function test_reupload_of_the_same_columns_reuses_the_confirmed_mapping(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        // Confirm a deliberately non-default mapping: the second column is the
        // one that matters, and the suggestion alone would not know that.
        $this->upload($user, $agent, "Название;Цена закупки;Цена\nКуртка;600;1200\n", ['name' => 0, 'price' => 1])['import']->assertOk();

        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Название;Цена закупки;Цена\nШапка;200;450\n")],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('mapping_reused', true)
            ->assertJsonPath('mapping.price', 1);
    }

    public function test_deleting_the_catalog_removes_the_dataset_and_its_products(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->upload($user, $agent, "Название;Цена\nКуртка;1200\n")['import']->assertOk();

        $this->actingAs($user)
            ->deleteJson(route('agents.catalog.destroy', $agent))
            ->assertOk()
            ->assertJsonPath('dataset', null);

        $this->assertDatabaseCount('dataset_products', 0);
        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    // --- multi-tenant isolation -------------------------------------------

    public function test_a_stranger_cannot_upload_a_catalog_to_someone_elses_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => $this->csv("Название;Цена\nКуртка;1200\n")],
            ['Accept' => 'application/json'],
        )->assertForbidden();

        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_a_stranger_cannot_read_or_delete_someone_elses_catalog(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->upload($owner, $agent, "Название;Цена\nКуртка;1200\n")['import']->assertOk();

        $this->actingAs($stranger)->getJson(route('agents.catalog.show', $agent))->assertForbidden();
        $this->actingAs($stranger)->deleteJson(route('agents.catalog.destroy', $agent))->assertForbidden();

        $this->assertSame(1, DatasetProduct::where('agent_id', $agent->id)->count());
    }

    /**
     * A pending upload's token names a file inside its own agent's directory, so
     * replaying one tenant's token against another tenant's agent finds nothing
     * — even though the attacker owns both agents and holds a valid token.
     */
    public function test_a_pending_upload_token_cannot_be_replayed_against_another_agent(): void
    {
        $user = User::factory()->create();
        $agentA = Agent::factory()->for($user)->create();
        $agentB = Agent::factory()->for($user)->create();

        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agentA),
            ['file' => $this->csv("Название;Цена\nКуртка;1200\n")],
            ['Accept' => 'application/json'],
        );

        $this->actingAs($user)->postJson(route('agents.catalog.import', $agentB), [
            'token' => $analyze->json('token'),
            'mapping' => ['name' => 0, 'price' => 1],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('agents.catalog.errors.upload_expired'));

        $this->assertDatabaseCount('dataset_products', 0);
    }

    public function test_a_traversal_token_is_rejected_by_validation(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->postJson(route('agents.catalog.import', $agent), [
            'token' => '../../../../etc/passwd',
            'mapping' => ['name' => 0],
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);
    }
}
