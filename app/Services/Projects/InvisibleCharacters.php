<?php

namespace App\Services\Projects;

use Illuminate\Http\Request;

final class InvisibleCharacters
{
    private const PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{00AD}\x{FEFF}]/u';

    public static function strip(string $value): string
    {
        return preg_replace(self::PATTERN, '', $value) ?? $value;
    }

    public static function stripFromRequest(Request $request, string ...$fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field) && is_string($request->input($field))) {
                $request->merge([$field => self::strip($request->input($field))]);
            }
        }
    }
}
