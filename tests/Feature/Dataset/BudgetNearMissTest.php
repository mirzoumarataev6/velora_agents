<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\User;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PixelShopCatalog;
use Tests\TestCase;

/**
 * "A good laptop for 6000-7000" against the real PixelShop catalog, where
 * nothing is priced in that range. The nearest products are 5999 (1 under)
 * and 7999 (999 over); the old relaxation offered 8199 / 8900 / 11999 — the
 * first three rows of the file.
 */
class BudgetNearMissTest extends TestCase
{
    use PixelShopCatalog;
    use RefreshDatabase;

    protected Agent $agent;

    protected ProductSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->for(User::factory())->create();
        $this->seedPixelShop($this->agent);
        $this->search = app(ProductSearchService::class);
    }

    /** @return array<int, float> */
    protected function nearestPrices(array $result): array
    {
        return array_map(fn (array $row) => self::priceOf($row), $result['nearest']);
    }

    public function test_the_nearest_miss_is_ranked_by_distance_from_the_range(): void
    {
        $result = $this->search->search($this->agent, ['min_price' => 6000, 'max_price' => 7000, 'category' => 'ноутбук']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([5999.0, 7999.0, 8199.0], $this->nearestPrices($result));
    }

    /** The call the model actually made in production: prices only, no category. */
    public function test_a_price_only_search_still_leads_with_the_one_just_below_the_floor(): void
    {
        $result = $this->search->search($this->agent, ['min_price' => 6000, 'max_price' => 7000]);

        $this->assertSame(5999.0, $this->nearestPrices($result)[0]);
        $this->assertNotContains(11999.0, $this->nearestPrices($result));
    }

    public function test_each_near_miss_carries_its_actual_gap(): void
    {
        $result = $this->search->search($this->agent, ['min_price' => 6000, 'max_price' => 7000, 'category' => 'ноутбук']);

        $this->assertSame(
            [['side' => 'below_min', 'amount' => 1], ['side' => 'above_max', 'amount' => 999], ['side' => 'above_max', 'amount' => 1199]],
            array_column($result['nearest'], 'budget_gap'),
        );
        $this->assertStringContainsString('NEAREST FIRST', $result['nearest_note']);
        $this->assertStringContainsString('budget is firm', $result['nearest_note']);
    }

    public function test_a_ceiling_only_budget_offers_the_cheapest_just_above_it(): void
    {
        $result = $this->search->search($this->agent, ['max_price' => 2000, 'category' => 'ноутбук']);

        $this->assertSame([2699.0, 4699.0, 5999.0], $this->nearestPrices($result));
        $this->assertSame('above_max', $result['nearest'][0]['budget_gap']['side']);
    }

    // -----------------------------------------------------------------
    // Exclusions
    // -----------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function macbookSpellings(): array
    {
        return [
            'latin' => ['MacBook'],
            'cyrillic, as the customer wrote it' => ['МакБук'],
            'lowercase with a space' => ['мак бук'],
        ];
    }

    #[DataProvider('macbookSpellings')]
    public function test_an_excluded_family_never_appears_among_the_near_misses(string $spelling): void
    {
        $result = $this->search->search($this->agent, [
            'min_price' => 6000, 'max_price' => 7000, 'category' => 'ноутбук', 'exclude' => [$spelling],
        ]);

        $this->assertSame([5999.0, 7999.0, 4699.0], $this->nearestPrices($result));

        foreach ($result['nearest'] as $row) {
            $this->assertStringNotContainsStringIgnoringCase('macbook', $row['name']);
        }
    }

    public function test_an_exclusion_applies_to_an_ordinary_search_too(): void
    {
        $result = $this->search->search($this->agent, ['category' => 'ноутбук', 'max_price' => 9000, 'exclude' => 'MacBook']);

        $this->assertNotEmpty($result['products']);
        foreach ($result['products'] as $row) {
            $this->assertStringNotContainsStringIgnoringCase('macbook', $row['name']);
        }
        $this->assertSame(['MacBook'], $result['filters']['exclude']);
    }

    public function test_the_tool_offers_an_exclude_parameter(): void
    {
        $exclude = $this->search->toolDefinition()['parameters']['properties']['exclude'];

        $this->assertSame('array', $exclude['type']);
        $this->assertStringContainsString('EVERY later search', $exclude['description']);
    }

    public function test_the_prompt_tells_the_model_to_carry_rejections_forward(): void
    {
        $instruction = $this->search->systemInstruction();

        $this->assertStringContainsString('WHAT THE CUSTOMER HAS REJECTED', $instruction);
        $this->assertStringContainsString('WHEN NOTHING FITS THE BUDGET', $instruction);
    }
}
