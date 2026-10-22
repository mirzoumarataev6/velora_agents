<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeSource;
use App\Services\Llm\LlmProviderInterface;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\Element\Text as WordText;
use PhpOffice\PhpWord\Element\TextRun as WordTextRun;
use PhpOffice\PhpWord\Element\Title as WordTitle;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\DomCrawler\Crawler;

class KnowledgeExtractionService
{
    public function __construct(protected LlmProviderInterface $llm) {}

    public function extract(KnowledgeSource $source): array
    {
        return match ($source->type) {
            'url' => $this->extractUrl($source),
            default => $this->extractFile($source),
        };
    }

    protected function extractFile(KnowledgeSource $source): array
    {
        if (empty($source->file_path) || ! Storage::disk('local')->exists($source->file_path)) {
            throw new RuntimeException('Source file is missing from storage.');
        }

        $path = Storage::disk('local')->path($source->file_path);
        $extension = strtolower(pathinfo($source->original_filename ?? $source->file_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            'xlsx', 'csv' => $this->extractSpreadsheet($path),
            'xml' => $this->extractXml($path),
            'png', 'jpg', 'jpeg', 'webp' => $this->extractImage($path, $source->mime_type),
            default => $this->extractPlainText($path),
        };
    }

    protected function extractPlainText(string $path): array
    {
        $text = file_get_contents($path) ?: '';

        return ['kind' => 'plain', 'text' => trim($text), 'meta' => ['char_count' => mb_strlen($text)]];
    }

    protected function extractPdf(string $path): array
    {
        $parser = new PdfParser;
        $pdf = $parser->parseFile($path);
        $text = trim($pdf->getText());
        $pageCount = count($pdf->getPages());

        if (mb_strlen($text) < max(50, $pageCount * 20)) {
            $result = $this->llm->extractFromImage(
                $path,
                'This PDF appears to be scanned or image-based. Extract all readable text and describe any products, prices, or tables in detail for a business knowledge base.',
                'application/pdf'
            );

            return [
                'kind' => 'plain',
                'text' => trim($result['text']),
                'meta' => ['page_count' => $pageCount, 'char_count' => mb_strlen($result['text']), 'ocr' => true],
            ];
        }

        return [
            'kind' => 'plain',
            'text' => $text,
            'meta' => ['page_count' => $pageCount, 'char_count' => mb_strlen($text)],
        ];
    }

    protected function extractDocx(string $path): array
    {
        $phpWord = WordIOFactory::load($path);
        $lines = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $line = match (true) {
                    $element instanceof WordTitle => str_repeat('#', min(6, max(1, (int) $element->getDepth()))).' '.$this->wordTextOf($element),
                    $element instanceof WordTextRun => $this->wordTextOf($element),
                    $element instanceof WordText => $element->getText(),
                    default => null,
                };

                if (! empty($line)) {
                    $lines[] = $line;
                }
            }
        }

        $text = implode("\n\n", $lines);

        return ['kind' => 'plain', 'text' => trim($text), 'meta' => ['char_count' => mb_strlen($text)]];
    }

    protected function wordTextOf(WordTitle|WordTextRun $element): string
    {
        $parts = [];

        foreach ($element->getElements() as $child) {
            if (method_exists($child, 'getText')) {
                $parts[] = (string) $child->getText();
            }
        }

        return trim(implode('', $parts));
    }

    protected function extractSpreadsheet(string $path): array
    {
        $spreadsheet = SpreadsheetIOFactory::load($path);
        $rowsData = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if ($rowsData === []) {
            throw new RuntimeException('The spreadsheet has no rows.');
        }

        $headers = array_map(fn ($h) => trim((string) $h), array_shift($rowsData));
        $headers = array_map(fn ($h, $i) => $h !== '' ? $h : "Column {$i}", $headers, array_keys($headers));

        $rows = [];
        foreach ($rowsData as $row) {
            if (collect($row)->every(fn ($cell) => trim((string) $cell) === '')) {
                continue;
            }

            $pairs = [];
            foreach ($headers as $i => $header) {
                $value = trim((string) ($row[$i] ?? ''));
                if ($value !== '') {
                    $pairs[] = "{$header}: {$value}";
                }
            }

            if ($pairs !== []) {
                $rows[] = implode(' | ', $pairs);
            }
        }

        if ($rows === []) {
            throw new RuntimeException('The spreadsheet has no usable data rows.');
        }

        return [
            'kind' => 'tabular',
            'header' => implode(' | ', $headers),
            'rows' => $rows,
            'meta' => ['row_count' => count($rows)],
        ];
    }

    protected function extractXml(string $path): array
    {
        $extracted = (new XmlCatalogExtractor)->extract($path);

        if (($extracted['kind'] ?? null) === 'tabular') {
            $extracted['rows_per_chunk'] = (int) config('agent.rag.xml_products_per_chunk');
        }

        return $extracted;
    }

    protected function extractImage(string $path, ?string $mimeType): array
    {
        $result = $this->llm->extractFromImage(
            $path,
            'Extract all text and describe the products/content in this image in detail for a knowledge base.',
            $mimeType
        );

        return [
            'kind' => 'plain',
            'text' => trim($result['text']),
            'meta' => ['char_count' => mb_strlen($result['text'])],
        ];
    }

    protected function extractUrl(KnowledgeSource $source): array
    {
        [$ip, $port] = SafeUrlGuard::assertSafe($source->url);
        $host = parse_url($source->url, PHP_URL_HOST);

        $client = new HttpClient([
            'timeout' => 15,
            'allow_redirects' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; VeloraBot/1.0; +https://velora.example/bot)',
            ],
            'curl' => [

                CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            ],
        ]);

        $response = $client->get($source->url);

        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new RuntimeException('The URL redirected to another address; please provide the direct URL.');
        }

        $html = (string) $response->getBody();

        return $this->parseHtmlContent($html);
    }

    public function parseHtmlContent(string $html): array
    {
        $crawler = new Crawler($html);

        $title = $this->firstText($crawler, 'title');
        $metaDescription = $this->metaContent($crawler, 'description');
        $metaKeywords = $this->metaContent($crawler, 'keywords');

        $contact = $this->extractContactInfo($crawler, $html);

        $root = $this->contentRoot($crawler);

        $root->filter('script, style, noscript, svg, template, iframe, form')->each(
            fn (Crawler $node) => $node->getNode(0)?->parentNode?->removeChild($node->getNode(0))
        );

        $blocks = [];
        $root->filter('h1, h2, h3, h4, h5, h6, p, li, dt, dd, address, blockquote, figcaption, th, td')->each(
            function (Crawler $node) use (&$blocks) {
                $text = $this->normalizeWhitespace($node->text(''));
                if ($text !== '') {
                    $blocks[] = $text;
                }
            }
        );

        $structured = implode("\n\n", array_values(array_unique($blocks)));
        $fullText = $this->normalizeWhitespace($root->text(''));

        $bodyText = mb_strlen($structured) >= mb_strlen($fullText) * 0.6 ? $structured : $fullText;

        if (mb_strlen($bodyText) < 200 && $this->looksJavaScriptRendered($crawler, $bodyText)) {
            throw new RuntimeException(
                'This page appears to be JavaScript-rendered — try providing the specific inner page URL or paste the text directly.'
            );
        }

        if ($bodyText === '' && $contact['summary'] === '') {
            throw new RuntimeException('No readable content was found at this URL.');
        }

        $parts = array_filter([
            $title,
            $contact['summary'],
            $metaDescription,
            $metaKeywords !== '' ? 'Keywords: '.$metaKeywords : '',
            $bodyText,
        ], fn ($p) => $p !== '' && $p !== null);

        $text = implode("\n\n", $parts);

        return [
            'kind' => 'plain',
            'text' => $text,
            'meta' => array_filter([
                'title' => $title,
                'char_count' => mb_strlen($text),
                'emails' => $contact['emails'],
                'phones' => $contact['phones'],
                'addresses' => $contact['addresses'],
            ], fn ($v) => $v !== '' && $v !== []),
        ];
    }

    protected function firstText(Crawler $crawler, string $selector): string
    {
        $node = $crawler->filter($selector);

        return $node->count() > 0 ? $this->normalizeWhitespace($node->first()->text('')) : '';
    }

    protected function metaContent(Crawler $crawler, string $name): string
    {
        $node = $crawler->filter("meta[name=\"{$name}\"]");

        return $node->count() > 0 ? trim((string) $node->first()->attr('content')) : '';
    }

    protected function contentRoot(Crawler $crawler): Crawler
    {
        foreach (['main', 'article', '[role="main"]', '[itemprop="mainContentOfPage"]', '#content', '#main'] as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                return $node->first();
            }
        }

        $body = $crawler->filter('body');

        return $body->count() > 0 ? $body->first() : $crawler;
    }

    protected function extractContactInfo(Crawler $crawler, string $html): array
    {
        $emails = [];
        $phones = [];
        $addresses = [];

        $crawler->filter('a[href^="mailto:"]')->each(function (Crawler $a) use (&$emails) {
            $email = trim(str_ireplace('mailto:', '', (string) $a->attr('href')));
            $email = explode('?', $email)[0];
            if ($email !== '') {
                $emails[] = $email;
            }
        });
        $crawler->filter('a[href^="tel:"]')->each(function (Crawler $a) use (&$phones) {
            $phone = trim(str_ireplace('tel:', '', (string) $a->attr('href')));
            if ($phone !== '') {
                $phones[] = $phone;
            }
        });

        $crawler->filter('address')->each(function (Crawler $node) use (&$addresses) {
            $text = $this->normalizeWhitespace($node->text(''));
            if ($text !== '') {
                $addresses[] = $text;
            }
        });

        foreach ($this->jsonLdBlocks($crawler) as $data) {
            $this->collectSchemaContact($data, $emails, $phones, $addresses);
        }

        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', strip_tags($html), $m)) {
            $emails = array_merge($emails, $m[0]);
        }
        if (preg_match_all('/\+?\d[\d\-\s().]{7,}\d/', strip_tags($html), $m)) {
            foreach ($m[0] as $candidate) {

                if (preg_match_all('/\d/', $candidate) >= 9) {
                    $phones[] = trim($candidate);
                }
            }
        }

        $emails = array_values(array_unique(array_filter($emails)));
        $phones = array_values(array_unique(array_filter($phones)));
        $addresses = array_values(array_unique(array_filter($addresses)));

        $lines = [];
        if ($addresses !== []) {
            $lines[] = 'Address: '.implode(' | ', $addresses);
        }
        if ($phones !== []) {
            $lines[] = 'Phone: '.implode(', ', $phones);
        }
        if ($emails !== []) {
            $lines[] = 'Email: '.implode(', ', $emails);
        }

        $summary = $lines === [] ? '' : "CONTACT INFO\n".implode("\n", $lines);

        return ['summary' => $summary, 'emails' => $emails, 'phones' => $phones, 'addresses' => $addresses];
    }

    protected function jsonLdBlocks(Crawler $crawler): array
    {
        $blocks = [];

        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$blocks) {
            $decoded = json_decode($node->text(''), true);
            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        });

        return $blocks;
    }

    protected function collectSchemaContact(mixed $data, array &$emails, array &$phones, array &$addresses): void
    {
        if (! is_array($data)) {
            return;
        }

        if (isset($data['telephone']) && is_string($data['telephone'])) {
            $phones[] = trim($data['telephone']);
        }
        if (isset($data['email']) && is_string($data['email'])) {
            $emails[] = trim(str_ireplace('mailto:', '', $data['email']));
        }
        if (isset($data['address'])) {
            $address = $data['address'];
            if (is_array($address)) {
                $formatted = $this->formatPostalAddress($address);
                if ($formatted !== '') {
                    $addresses[] = $formatted;
                }
            } elseif (is_string($address)) {
                $addresses[] = trim($address);
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collectSchemaContact($value, $emails, $phones, $addresses);
            }
        }
    }

    protected function formatPostalAddress(array $address): string
    {
        $parts = array_filter([
            $address['streetAddress'] ?? null,
            $address['postalCode'] ?? null,
            $address['addressLocality'] ?? null,
            $address['addressRegion'] ?? null,
            $address['addressCountry'] ?? null,
        ], fn ($p) => is_string($p) && trim($p) !== '');

        return trim(implode(', ', array_map('trim', $parts)));
    }

    protected function looksJavaScriptRendered(Crawler $crawler, string $visibleText): bool
    {
        $hasSpaRoot = $crawler->filter('#app, #root, [data-reactroot], [data-server-rendered], [ng-app], [ng-version]')->count() > 0;
        $scriptCount = $crawler->filter('script')->count();

        return mb_strlen(trim($visibleText)) < 200 && ($hasSpaRoot || $scriptCount >= 3);
    }

    protected function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
