<?php

namespace Tests\Unit\Dataset;

use App\Services\Dataset\NormalizesSearchTerms;
use PHPUnit\Framework\TestCase;

/**
 * The stems both product sources match on.
 *
 * A catalog is written in the nominative singular and customers are not, so the
 * ending has to come off before the LIKE — but only far enough that the result
 * is still the word. Cut too little and "ноутбуки" misses "Ноутбук"; cut too
 * much and every query matches half the shop.
 */
class SearchTermsTest extends TestCase
{
    protected object $terms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->terms = new class
        {
            use NormalizesSearchTerms;

            /** @return string[] */
            public function of(string $query): array
            {
                return $this->terms($query);
            }
        };
    }

    public function test_a_plural_is_reduced_to_the_stem_of_its_singular(): void
    {
        $this->assertSame(['ноутбук'], $this->terms->of('ноутбуки'));
        $this->assertSame(['игров'], $this->terms->of('игровые'));
        $this->assertSame(['игров'], $this->terms->of('игровой'));
        $this->assertSame(['laptop'], $this->terms->of('laptops'));
    }

    public function test_a_word_and_its_inflections_reduce_to_the_same_stem(): void
    {
        $singular = $this->terms->of('ноутбук');
        $plural = $this->terms->of('ноутбуки');
        $genitive = $this->terms->of('ноутбуков');

        $this->assertSame($singular, $plural);
        $this->assertSame($singular, $genitive);
    }

    public function test_a_short_word_is_left_alone(): void
    {
        // Below the floor there is no ending worth removing — "цена" cut to
        // "цен" starts matching "ценность" and "оценка".
        $this->assertSame(['цена'], $this->terms->of('цена'));
        $this->assertSame(['мышь'], $this->terms->of('мышь'));
    }

    public function test_a_stem_is_never_cut_below_four_characters(): void
    {
        foreach ($this->terms->of('куртка шапка обувь') as $term) {
            $this->assertGreaterThanOrEqual(4, mb_strlen($term));
        }
    }

    public function test_wildcards_are_stripped_and_leave_nothing_to_match(): void
    {
        $this->assertSame([], $this->terms->of('%'));
        $this->assertSame([], $this->terms->of('_ %'));
    }

    public function test_the_term_list_is_bounded_and_deduplicated(): void
    {
        $this->assertSame(['куртк'], $this->terms->of('куртка куртки куртке'));
        $this->assertCount(6, $this->terms->of('один два три четыре пять шесть семь восемь'));
    }
}
