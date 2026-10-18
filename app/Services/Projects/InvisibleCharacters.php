<?php

namespace App\Services\Projects;

use Illuminate\Http\Request;

/**
 * PHASE 3 item 21: a task title, column, label or board name built (in
 * whole or in part) from zero-width/invisible Unicode characters renders
 * as nothing different from the same name without them — "Launch" and
 * "Launch​" are pixel-identical — while comparing unequal everywhere
 * that matters: item 11's case-insensitive duplicate-name check, a search
 * match, a future export. A visitor can paste one in from a chat app, a
 * PDF, or a deliberately-crafted string without any way to notice.
 *
 * Stripped, not rejected: the validation failure these characters would
 * otherwise produce ("this field contains an invalid character") has
 * nothing visible to point the owner at — unlike item 11's duplicate-name
 * rejection, where the two colliding names are right there on screen, an
 * invisible character gives them nothing to go find and remove. Silently
 * cleaning it is what every other field here already does for ordinary
 * leading/trailing whitespace (trim()); this is the same move for
 * whitespace-shaped characters trim() doesn't know about.
 *
 * Applied at the request boundary (Request::merge(), before validate()),
 * not after — so a name built ENTIRELY of invisible characters is stripped
 * down to "" first and then genuinely fails the field's own existing
 * `required` rule, the same error an owner gets for leaving the field
 * empty outright, rather than silently saving a blank-looking row that
 * passed validation on a string of zero-width characters nobody could see.
 */
final class InvisibleCharacters
{
    /**
     * U+200B-U+200F: zero-width space, ZWNJ, ZWJ, zero-width no-break
     * space (legacy BOM), and the LTR/RTL marks. U+202A-U+202E: the bidi
     * embedding/override controls. U+2060-U+2069: word joiner and the
     * newer bidi isolate controls. U+00AD: soft hyphen (invisible unless a
     * line actually breaks there, which nothing in this app does).
     * U+FEFF: BOM, also reachable by this range when it leads a string
     * rather than sitting mid-text.
     */
    private const PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{00AD}\x{FEFF}]/u';

    public static function strip(string $value): string
    {
        return preg_replace(self::PATTERN, '', $value) ?? $value;
    }

    /**
     * Shared by every controller that writes a task title, column, label
     * or board name (ProjectTaskController, BoardController) — one place
     * doing the merge-before-validate dance instead of five copies of it.
     * Only touches a field the request actually carries: several of these
     * rules are `sometimes` (a partial task update), and merging in a
     * stripped '' for a field the request never sent would wrongly make
     * it look submitted.
     */
    public static function stripFromRequest(Request $request, string ...$fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field) && is_string($request->input($field))) {
                $request->merge([$field => self::strip($request->input($field))]);
            }
        }
    }
}
