<?php

namespace Tests\Unit\Projects;

use App\Services\Projects\InvisibleCharacters;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PHASE 3 item 21: "Launch" and "Launch\u{200B}" render pixel-identical but
 * compare unequal everywhere that matters — item 11's duplicate-name check,
 * a search match. strip() removes the characters that make that true;
 * stripFromRequest() is the merge-before-validate glue every write path
 * (ProjectTaskController, BoardController) calls through.
 */
class InvisibleCharactersTest extends TestCase
{
    public static function invisibleCharacterProvider(): array
    {
        return [
            'zero-width space' => ["\u{200B}"],
            'zero-width non-joiner' => ["\u{200C}"],
            'zero-width joiner' => ["\u{200D}"],
            'left-to-right mark' => ["\u{200E}"],
            'right-to-left mark' => ["\u{200F}"],
            'left-to-right embedding' => ["\u{202A}"],
            'right-to-left override' => ["\u{202E}"],
            'word joiner' => ["\u{2060}"],
            'right-to-left isolate' => ["\u{2067}"],
            'soft hyphen' => ["\u{00AD}"],
            'byte order mark' => ["\u{FEFF}"],
        ];
    }

    #[DataProvider('invisibleCharacterProvider')]
    public function test_it_strips_the_character_without_touching_the_visible_text(string $invisible): void
    {
        $this->assertSame('Launch', InvisibleCharacters::strip('Lau'.$invisible.'nch'));
    }

    public function test_a_name_made_entirely_of_invisible_characters_strips_to_empty(): void
    {
        $this->assertSame('', InvisibleCharacters::strip("\u{200B}\u{FEFF}\u{200C}"));
    }

    public function test_ordinary_text_including_cyrillic_is_left_alone(): void
    {
        $this->assertSame('Запуск продукта', InvisibleCharacters::strip('Запуск продукта'));
    }

    public function test_strip_from_request_only_touches_a_field_the_request_actually_carries(): void
    {
        $request = Request::create('/', 'POST', ['title' => "Launch\u{200B}", 'other' => 'untouched']);

        InvisibleCharacters::stripFromRequest($request, 'title', 'absent_field');

        $this->assertSame('Launch', $request->input('title'));
        $this->assertFalse($request->has('absent_field'));
        $this->assertSame('untouched', $request->input('other'));
    }
}
