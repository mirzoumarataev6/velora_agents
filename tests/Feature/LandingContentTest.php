<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Tests\TestCase;

/**
 * Guards for the landing page's two silent failure modes.
 *
 * Both are invisible in a normal page load and only surface for a subset of
 * visitors, which is exactly why they belong in the suite rather than in a
 * reviewer's eyes:
 *
 *   1. A data-i18n path that doesn't resolve renders fine on first load (the
 *      server-side text is already in the markup) and then blanks out the
 *      instant someone switches language.
 *   2. A key that exists in one locale's landing file but not another's does
 *      the same thing, but only in the locale that's missing it.
 */
class LandingContentTest extends TestCase
{
    private const NAMESPACES = ['landing', 'nav', 'common'];

    public function test_every_i18n_path_in_the_page_resolves_in_every_locale(): void
    {
        $html = $this->get('/ru')->assertOk()->getContent();

        preg_match_all('#data-i18n(?:-al|-ph)?="([^"]+)"#', $html, $matches);
        $paths = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($paths, 'The landing should tag its content for client-side locale swapping.');

        $failures = [];

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $bundle = [];
            foreach (self::NAMESPACES as $namespace) {
                $bundle[$namespace] = trans($namespace, [], $locale);
            }

            foreach ($paths as $path) {
                $value = $this->resolve($bundle, $path);

                if (! is_string($value) || $value === '') {
                    $failures[] = $locale.' → '.$path;
                }
            }
        }

        $this->assertSame([], $failures, "These data-i18n paths do not resolve to a string:\n".implode("\n", $failures));
    }

    public function test_landing_strings_have_the_same_shape_in_every_locale(): void
    {
        $reference = $this->flatten(trans('landing', [], SetLocale::DEFAULT_LOCALE));
        sort($reference);

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            if ($locale === SetLocale::DEFAULT_LOCALE) {
                continue;
            }

            $actual = $this->flatten(trans('landing', [], $locale));
            sort($actual);

            $this->assertSame(
                [],
                array_values(array_diff($reference, $actual)),
                'Keys present in '.SetLocale::DEFAULT_LOCALE." but missing from {$locale}."
            );
            $this->assertSame(
                [],
                array_values(array_diff($actual, $reference)),
                "Keys present in {$locale} but missing from ".SetLocale::DEFAULT_LOCALE.'.'
            );
        }
    }

    public function test_language_stat_matches_the_locales_the_product_serves(): void
    {
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $stat = trans('landing.stats.items', [], $locale)[2];

            $this->assertSame((string) count(SetLocale::SUPPORTED_LOCALES), $stat['value']);
        }
    }

    public function test_html_is_compressed_when_the_client_accepts_gzip(): void
    {
        $plain = $this->get('/ru')->assertOk();
        $this->assertNull($plain->headers->get('Content-Encoding'));

        $gzipped = $this->get('/ru', ['Accept-Encoding' => 'gzip'])->assertOk();

        $this->assertSame('gzip', $gzipped->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $gzipped->headers->get('Vary'));

        // The body must be real gzip that decodes back to the landing page.
        // (Not compared byte-for-byte against the uncompressed response: Vite
        // emits each font preload only once per PHP process, so two renders in
        // one test differ in <head> for reasons that have nothing to do with
        // compression.)
        $decoded = gzdecode($gzipped->getContent());

        $this->assertIsString($decoded, 'The compressed body should be valid gzip.');
        $this->assertStringContainsString('window.__i18n', $decoded);
        $this->assertStringContainsString('data-i18n="landing.hero.sub"', $decoded);
        $this->assertLessThan(
            strlen($decoded) / 2,
            strlen($gzipped->getContent()),
            'Compression should more than halve the landing document.'
        );
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function resolve(array $tree, string $path): mixed
    {
        $node = $tree;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
