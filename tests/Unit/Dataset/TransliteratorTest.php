<?php

namespace Tests\Unit\Dataset;

use App\Services\Dataset\ColumnMapper;
use App\Services\Dataset\Transliterator;
use Tests\TestCase;

/**
 * Reading a latin-spelled Cyrillic column name back into Cyrillic.
 *
 * What is asserted here is not "the transliteration is correct" — there is no
 * single correct transliteration, which is the entire problem — but that the
 * right word is somewhere IN the candidate set, because the vocabulary is what
 * picks between them. A candidate list containing "цена" and three spellings
 * nobody uses is a success; one containing only "кена" is a failure.
 */
class TransliteratorTest extends TestCase
{
    /**
     * @param  string[]  $expected
     */
    protected function assertReads(string $latin, array $expected): void
    {
        $candidates = Transliterator::candidates(ColumnMapper::fold($latin));

        foreach ($expected as $word) {
            $this->assertContains($word, $candidates, "[{$latin}] should be readable as [{$word}]");
        }
    }

    public function test_the_common_russian_column_names_are_read(): void
    {
        $this->assertReads('nazvanie', ['название']);
        $this->assertReads('naimenovanie', ['наименование']);
        $this->assertReads('cena', ['цена']);
        $this->assertReads('ostatok', ['остаток']);
        $this->assertReads('kategoriya', ['категория']);
        $this->assertReads('opisanie', ['описание']);
        $this->assertReads('artikul', ['артикул']);
        $this->assertReads('ssylka', ['ссылка']);
    }

    public function test_the_same_word_is_read_through_every_spelling_convention(): void
    {
        // ц is written c, ts or tc depending on who typed it, and none of the
        // three is more correct than the others.
        foreach (['cena', 'tsena', 'tcena'] as $spelling) {
            $this->assertReads($spelling, ['цена']);
        }

        // я is ya, ia or ja; х is h or kh.
        $this->assertReads('kategoriya', ['категория']);
        $this->assertReads('kategorija', ['категория']);
        $this->assertReads('shtrihkod', ['штрихкод']);
        $this->assertReads('shtrikhkod', ['штрихкод']);
    }

    public function test_the_local_languages_are_read_too(): void
    {
        // The point of transliterating rather than listing: these come free with
        // the vocabulary already having the Cyrillic words.
        $this->assertReads('narkh', ['нарх']);
        $this->assertReads('nom', ['ном']);
        $this->assertReads('miqdor', ['миқдор']);
        $this->assertReads('gurukh', ['гурӯҳ', 'гурух']);
    }

    public function test_separators_are_read_both_ways(): void
    {
        // "кол-во" folds to "кол во", so the spaced reading is the one that
        // matches — but "колво" is just as plausible a thing to have typed.
        $candidates = Transliterator::candidates(ColumnMapper::fold('kol_vo'));

        $this->assertContains('кол во', $candidates);
        $this->assertContains('колво', $candidates);
    }

    public function test_a_name_already_in_cyrillic_is_left_alone(): void
    {
        // Nothing to do, and inventing latin-derived spellings of a word we can
        // already read would only add ways to be wrong.
        $this->assertSame([], Transliterator::candidates('название'));
        $this->assertSame([], Transliterator::candidates('цена'));
    }

    public function test_nothing_is_produced_for_input_that_is_not_a_word(): void
    {
        $this->assertSame([], Transliterator::candidates(''));
        $this->assertSame([], Transliterator::candidates('123'));
        $this->assertSame([], Transliterator::candidates(str_repeat('a', 64)));
    }

    public function test_the_candidate_set_stays_bounded(): void
    {
        // Every letter with several readings multiplies the set, so a long name
        // of nothing but ambiguous letters is the worst case. It must stay a
        // lookup, not a combinatorial walk.
        foreach (['ceyuhqgxej', 'yyyyyyyyyyyyyyyy', 'chegirmayehquv'] as $word) {
            $this->assertLessThanOrEqual(
                Transliterator::MAX_CANDIDATES * 2,
                count(Transliterator::candidates($word)),
                "[{$word}] produced too many candidates",
            );
        }
    }
}
