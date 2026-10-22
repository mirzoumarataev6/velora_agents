<?php

namespace App\Services\Knowledge;

use RuntimeException;
use XMLReader;

class XmlCatalogExtractor
{
    protected const SNIFF_BYTES = 8192;

    public function extract(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('XML file is not readable.');
        }

        $encoding = $this->detectEncoding($path);

        $inMemoryXml = $encoding !== 'UTF-8' ? $this->readTranscoded($path, $encoding) : null;

        $head = $this->sniffHead($path, $encoding);
        $dialect = $this->detectDialect($head);
        $spec = $this->catalogSpec($dialect, $head);

        $products = [];
        if ($spec !== null) {
            $products = $this->parseStreaming($path, $inMemoryXml, $spec);

            if ($products === []) {
                $products = $this->parseDomRecovery($path, $inMemoryXml, $spec);
            }
        }

        if ($products === []) {
            return $this->fallbackText($path, $inMemoryXml, $dialect);
        }

        return [
            'kind' => 'tabular',
            'header' => '',
            'rows' => $products,
            'meta' => [
                'xml_dialect' => $dialect,
                'product_count' => count($products),
            ],
        ];
    }

    protected function detectEncoding(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        if (preg_match('/<\?xml[^>]*encoding=["\']([^"\']+)["\']/i', $head, $m)) {
            $declared = strtoupper(trim($m[1]));
            if (in_array($declared, ['WINDOWS-1251', 'CP1251', 'CP-1251'], true)) {
                return 'WINDOWS-1251';
            }
            if (str_contains($declared, 'UTF-8') || str_contains($declared, 'UTF8')) {
                return 'UTF-8';
            }
        }

        if (! mb_check_encoding($head, 'UTF-8') && mb_check_encoding($head, 'WINDOWS-1251')) {
            return 'WINDOWS-1251';
        }

        return 'UTF-8';
    }

    protected function readTranscoded(string $path, string $encoding): string
    {
        $raw = (string) file_get_contents($path);
        $utf8 = mb_convert_encoding($raw, 'UTF-8', $encoding);

        if (preg_match('/<\?xml[^>]*\?>/i', $utf8)) {
            return preg_replace(
                '/(<\?xml[^>]*encoding=["\'])[^"\']+(["\'])/i',
                '${1}UTF-8${2}',
                $utf8,
                1
            ) ?? $utf8;
        }

        return $utf8;
    }

    protected function sniffHead(string $path, string $encoding): string
    {
        $head = (string) file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        return $encoding === 'UTF-8' ? $head : mb_convert_encoding($head, 'UTF-8', $encoding);
    }

    protected function detectDialect(string $head): string
    {
        if (stripos($head, '<yml_catalog') !== false) {
            return 'yml';
        }

        if (stripos($head, 'http://base.google.com/ns/1.0') !== false || preg_match('/<g:[a-z_]/i', $head)) {
            return 'merchant';
        }

        if (stripos($head, 'КоммерческаяИнформация') !== false || stripos($head, '<Товар') !== false) {
            return 'commerceml';
        }

        if ($this->genericNodeName($head) !== null) {
            return 'generic';
        }

        return 'unknown';
    }

    protected function genericNodeName(string $head): ?string
    {
        foreach (['product', 'offer', 'item'] as $name) {
            if (preg_match('/<'.$name.'[\s>]/i', $head)) {
                return $name;
            }
        }

        return null;
    }

    protected function catalogSpec(string $dialect, string $head): ?array
    {
        return match ($dialect) {
            'yml' => [
                'node' => 'offer',
                'mapper' => fn (\DOMElement $n, array $ctx) => $this->fromYml($n, $ctx),
                'collector' => $this->ymlCategoryCollector(),
            ],
            'merchant' => [
                'node' => 'item',
                'mapper' => fn (\DOMElement $n) => $this->fromMerchant($n),
                'collector' => null,
            ],
            'commerceml' => [
                'node' => 'Товар',
                'mapper' => fn (\DOMElement $n) => $this->fromCommerceMl($n),
                'collector' => null,
            ],
            'generic' => [
                'node' => $this->genericNodeName($head) ?? 'product',
                'mapper' => fn (\DOMElement $n) => $this->fromGeneric($n),
                'collector' => null,
            ],
            default => null,
        };
    }

    protected function parseStreaming(string $path, ?string $inMemoryXml, array $spec): array
    {
        $reader = new XMLReader;
        $opened = $inMemoryXml !== null
            ? $reader->XML($inMemoryXml, null, LIBXML_NOERROR | LIBXML_NOWARNING)
            : $reader->open($path, null, LIBXML_NOERROR | LIBXML_NOWARNING);

        if (! $opened) {
            return [];
        }

        $collector = $spec['collector'];
        $mapper = $spec['mapper'];
        $node = $spec['node'];
        $previous = libxml_use_internal_errors(true);
        $context = $collector['context'] ?? [];
        $rows = [];

        try {

            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($collector !== null && $reader->localName === $collector['name']) {
                    $expanded = $reader->expand();
                    if ($expanded instanceof \DOMElement) {
                        $collector['handler']($expanded, $context);
                    }

                    continue;
                }

                if ($reader->localName !== $node) {
                    continue;
                }

                $expanded = $reader->expand();
                if (! $expanded instanceof \DOMElement) {
                    break;
                }

                $line = $mapper($expanded, $context);
                if (trim($line) !== '') {
                    $rows[] = $line;
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $rows;
    }

    protected function parseDomRecovery(string $path, ?string $inMemoryXml, array $spec): array
    {
        $xml = $inMemoryXml ?? (string) file_get_contents($path);

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->recover = true;
        $dom->strictErrorChecking = false;
        $loaded = @$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $context = $spec['collector']['context'] ?? [];

        if ($spec['collector'] !== null) {
            foreach ($dom->getElementsByTagName($spec['collector']['name']) as $node) {
                if ($node instanceof \DOMElement) {
                    $spec['collector']['handler']($node, $context);
                }
            }
        }

        $rows = [];
        foreach ($dom->getElementsByTagName($spec['node']) as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $line = $spec['mapper']($node, $context);
            if (trim($line) !== '') {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    protected function ymlCategoryCollector(): array
    {
        return [
            'name' => 'category',
            'context' => ['categories' => []],
            'handler' => function (\DOMElement $node, array &$context): void {
                $id = $node->getAttribute('id');
                $name = trim($node->textContent);
                if ($id !== '' && $name !== '') {
                    $context['categories'][$id] = $name;
                }
            },
        ];
    }

    protected function fromYml(\DOMElement $node, array $context): string
    {
        $name = $this->child($node, 'name');
        if ($name === '') {
            $name = trim($this->child($node, 'vendor').' '.$this->child($node, 'model'));
        }

        $categories = $context['categories'] ?? [];
        $categoryId = $this->child($node, 'categoryId');

        return $this->formatProduct([
            'name' => $name,
            'price' => $this->priceWithCurrency($this->child($node, 'price'), $this->child($node, 'currencyId')),
            'available' => $this->availability($node->getAttribute('available') ?: $this->child($node, 'available')),
            'category' => $categories[$categoryId] ?? '',
            'sku' => $this->child($node, 'vendorCode') ?: $node->getAttribute('id'),
        ]);
    }

    protected function fromMerchant(\DOMElement $node): string
    {
        return $this->formatProduct([
            'name' => $this->child($node, 'title'),
            'price' => $this->child($node, 'price') ?: $this->child($node, 'sale_price'),
            'available' => $this->availability($this->child($node, 'availability')),
            'category' => $this->child($node, 'product_type') ?: $this->child($node, 'google_product_category'),
            'sku' => $this->child($node, 'id') ?: $this->child($node, 'mpn'),
        ]);
    }

    protected function fromCommerceMl(\DOMElement $node): string
    {

        $price = '';
        foreach ($node->getElementsByTagName('ЦенаЗаЕдиницу') as $priceNode) {
            $price = trim($priceNode->textContent);
            break;
        }

        return $this->formatProduct([
            'name' => $this->child($node, 'Наименование'),
            'price' => $price,
            'available' => '',
            'category' => $this->child($node, 'Группа'),
            'sku' => $this->child($node, 'Артикул'),
        ]);
    }

    protected function fromGeneric(\DOMElement $node): string
    {
        $fields = [
            'name' => $this->firstChild($node, ['name', 'title', 'наименование', 'название', 'model']),
            'price' => $this->firstChild($node, ['price', 'цена', 'cost']),
            'available' => $this->availability(
                $node->getAttribute('available') ?: $this->firstChild($node, ['available', 'availability', 'stock', 'наличие', 'quantity'])
            ),
            'category' => $this->firstChild($node, ['category', 'категория', 'product_type', 'group', 'группа']),
            'sku' => $this->firstChild($node, ['sku', 'article', 'артикул', 'vendorCode', 'id']) ?: $node->getAttribute('id'),
        ];

        if (trim(implode('', $fields)) === '') {
            return $this->flattenLeaves($node);
        }

        return $this->formatProduct($fields);
    }

    protected function formatProduct(array $fields): string
    {
        $labels = [
            'name' => 'Товар',
            'price' => 'Цена',
            'available' => 'Наличие',
            'category' => 'Категория',
            'sku' => 'Артикул',
        ];

        $parts = [];
        foreach ($labels as $key => $label) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value !== '') {
                $parts[] = "{$label}: {$value}";
            }
        }

        return implode(' | ', $parts);
    }

    protected function priceWithCurrency(string $price, string $currency): string
    {
        $price = trim($price);
        $currency = trim($currency);

        return $price !== '' && $currency !== '' ? "{$price} {$currency}" : $price;
    }

    protected function availability(string $raw): string
    {
        $value = trim(mb_strtolower($raw));

        if ($value === '') {
            return '';
        }

        return match (true) {
            in_array($value, ['true', '1', 'yes', 'in_stock', 'in stock', 'preorder', 'да', 'в наличии', 'есть'], true) => 'в наличии',
            in_array($value, ['false', '0', 'no', 'out_of_stock', 'out of stock', 'нет', 'нет в наличии'], true) => 'нет в наличии',
            ctype_digit($value) => (int) $value > 0 ? 'в наличии' : 'нет в наличии',
            default => $raw,
        };
    }

    protected function child(\DOMElement $node, string $name): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strcasecmp($child->localName, $name) === 0) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    protected function firstChild(\DOMElement $node, array $names): string
    {
        foreach ($names as $name) {
            $value = $this->child($node, $name);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function flattenLeaves(\DOMElement $node): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $text = trim($child->textContent);
                if ($text !== '') {
                    $parts[] = ucfirst($child->localName).': '.$text;
                }
            }
        }

        return implode(' | ', $parts);
    }

    protected function fallbackText(string $path, ?string $inMemoryXml, string $dialect): array
    {
        $xml = $inMemoryXml ?? (string) file_get_contents($path);

        $text = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $xml) ?? $xml;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            throw new RuntimeException('No readable content was found in this XML file.');
        }

        return [
            'kind' => 'plain',
            'text' => $text,
            'meta' => [
                'xml_dialect' => $dialect === 'unknown' ? 'unknown' : $dialect,
                'char_count' => mb_strlen($text),
            ],
        ];
    }
}
