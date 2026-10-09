<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WikimediaImageSearch
{
    /** Terms that describe a kind of image, not the requested product model. */
    private const GENERIC_QUERY_TERMS = [
        'photo', 'picture', 'image', 'фото', 'фотография', 'картинка', 'изображение',
        'laptop', 'notebook', 'computer', 'ноутбук', 'компьютер', 'gaming', 'игровой',
    ];

    private const PRODUCT_TYPE_TERMS = [
        'laptop' => ['laptop', 'notebook', 'macbook', 'ноутбук', 'portable'],
        'phone' => ['phone', 'smartphone', 'iphone', 'телефон', 'смартфон'],
        'tablet' => ['tablet', 'ipad', 'планшет'],
        'monitor' => ['monitor', 'display', 'монитор'],
    ];

    /** Commons is used because its file metadata exposes attribution and license information. */
    public function search(string $query, int $limit = 3, ?string $productType = null): array
    {
        $query = trim(mb_substr($query, 0, 180));
        if ($query === '') {
            return [];
        }

        $productType ??= self::detectProductType($query);

        try {
            $response = Http::acceptJson()->withHeaders([
                'User-Agent' => 'VeloraAgents/1.0 (business assistant; image attribution enabled)',
            ])->connectTimeout(3)->timeout(8)->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query', 'format' => 'json', 'generator' => 'search',
                'gsrsearch' => 'filetype:bitmap '.$query.($productType !== null && ! str_contains(mb_strtolower($query), $productType) ? ' '.$productType : ''), 'gsrnamespace' => 6,
                // Filtering two lexical hits often discarded the unrelated
                // hits before ever considering an actual product photo.
                'gsrlimit' => 10, 'prop' => 'imageinfo',
                'iiprop' => 'url|extmetadata', 'iiurlwidth' => 720,
            ]);

            if ($response->failed() || $response->json('error') !== null) {
                throw new RuntimeException('Wikimedia search failed (HTTP '.$response->status().').');
            }

            $pages = $response->json('query.pages', []);
            $images = [];
            foreach ($pages as $page) {
                $info = $page['imageinfo'][0] ?? [];
                $url = $info['thumburl'] ?? $info['url'] ?? null;
                // Commons currently returns resized search previews from
                // thumb.wikimedia.org, while original files use
                // upload.wikimedia.org. Both are first-party Wikimedia hosts;
                // accepting only originals made a successful live search look
                // like an empty result and silently prevented photo delivery.
                $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
                if (! is_string($url)
                    || ! in_array($host, ['upload.wikimedia.org', 'thumb.wikimedia.org'], true)
                    || ! str_starts_with($url, 'https://')) {
                    continue;
                }

                $metadata = $info['extmetadata'] ?? [];
                $license = trim(strip_tags((string) ($metadata['LicenseShortName']['value'] ?? '')));
                $author = trim(strip_tags((string) ($metadata['Artist']['value'] ?? '')));
                $licenseUrl = trim(strip_tags((string) ($metadata['LicenseUrl']['value'] ?? '')));
                // Commons includes files whose reuse terms are unclear or whose
                // machine-readable attribution is missing. Do not send those.
                if ($license === '' || $licenseUrl === '') {
                    continue;
                }
                $title = (string) ($page['title'] ?? '');
                // Commons text search is lexical: "OMEN 16" currently returns
                // photographs of an art installation called Omen. Sending a
                // plausible-looking but unrelated photo is worse than returning
                // no photo, so a model number and distinctive name must occur
                // in the file title before it can reach the customer.
                if (! $this->titleMatchesModelQuery($title, $query, $productType)) {
                    continue;
                }
                $caption = implode(' · ', array_filter([
                    $author !== '' ? '© '.$author : null,
                    $license.' · '.$licenseUrl,
                    'Wikimedia Commons: https://commons.wikimedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title)),
                ]));
                $images[] = ['url' => $url, 'caption' => mb_substr($caption, 0, 900), 'source' => $title];
                if (count($images) >= min(3, max(1, $limit))) {
                    break;
                }
            }

            return $images;
        } catch (\Throwable $e) {
            Log::notice('Wikimedia image search unavailable', ['error' => $e->getMessage()]);

            throw new RuntimeException('Image search is temporarily unavailable.', 0, $e);
        }
    }

    public static function detectProductType(string $text): ?string
    {
        $text = mb_strtolower($text);
        foreach (self::PRODUCT_TYPE_TERMS as $type => $terms) {
            foreach ($terms as $term) {
                $end = preg_match('/\p{Cyrillic}/u', $term) ? '\\p{L}*' : '(?![\\p{L}\\p{N}])';
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').$end.'/u', $text)) {
                    return $type;
                }
            }
        }

        return null;
    }

    private function titleMatchesModelQuery(string $title, string $query, ?string $productType): bool
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $matches);
        $terms = array_values(array_filter($matches[0] ?? [], fn (string $term) => ctype_digit($term)
            || (mb_strlen($term) >= 3 && ! in_array($term, self::GENERIC_QUERY_TERMS, true))
        ));

        $normalizedTitle = mb_strtolower($title);
        if ($productType === null || ! isset(self::PRODUCT_TYPE_TERMS[$productType])) {
            // A model name alone can also name a band, event, artwork or
            // person. Without a product class there is no safe way to decide.
            return false;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $normalizedTitle, $titleMatches);
        $titleTerms = $titleMatches[0] ?? [];
        $typeMatched = array_intersect($titleTerms, self::PRODUCT_TYPE_TERMS[$productType]) !== [];
        if (! $typeMatched) {
            return false;
        }

        if ($terms === []) {
            // A query of ONLY generic/type words ("laptop") is a deliberate
            // browse by product type, and returning true for any matching
            // title is the intended, permissive behaviour.
            //
            // But a query that also had a short, SPECIFIC word too short to
            // survive the filter above (a bare brand like "HP", left over
            // once a model name was lost upstream) is a narrower request than
            // "any laptop" — returning true unconditionally here is what let
            // an unrelated same-type photo ("HP Laptop 14S") through for a
            // request that should have stayed a specific model. Require that
            // short word itself to appear in the title instead.
            $shortSpecificTerms = array_values(array_filter($matches[0] ?? [], fn (string $term) => ! ctype_digit($term)
                && mb_strlen($term) < 3 && ! in_array($term, self::GENERIC_QUERY_TERMS, true)
            ));

            return $shortSpecificTerms === [] || array_intersect($shortSpecificTerms, $titleTerms) !== [];
        }

        // Require the model as one sequence. Separate occurrences of "Omen"
        // and "16" let the concert photo Profane_Omen_..._16.jpg through.
        $queryLength = count($terms);
        foreach ($titleTerms as $index => $unused) {
            if (array_slice($titleTerms, $index, $queryLength) === $terms) {
                return true;
            }
        }

        return false;
    }
}
