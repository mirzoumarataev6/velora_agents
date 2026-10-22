<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WikimediaImageSearch
{
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

                if ($license === '' || $licenseUrl === '') {
                    continue;
                }
                $title = (string) ($page['title'] ?? '');

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

            return false;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $normalizedTitle, $titleMatches);
        $titleTerms = $titleMatches[0] ?? [];
        $typeMatched = array_intersect($titleTerms, self::PRODUCT_TYPE_TERMS[$productType]) !== [];
        if (! $typeMatched) {
            return false;
        }

        if ($terms === []) {

            $shortSpecificTerms = array_values(array_filter($matches[0] ?? [], fn (string $term) => ! ctype_digit($term)
                && mb_strlen($term) < 3 && ! in_array($term, self::GENERIC_QUERY_TERMS, true)
            ));

            return $shortSpecificTerms === [] || array_intersect($shortSpecificTerms, $titleTerms) !== [];
        }

        $queryLength = count($terms);
        foreach ($titleTerms as $index => $unused) {
            if (array_slice($titleTerms, $index, $queryLength) === $terms) {
                return true;
            }
        }

        return false;
    }
}
