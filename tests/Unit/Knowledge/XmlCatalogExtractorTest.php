<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\XmlCatalogExtractor;
use PHPUnit\Framework\TestCase;

class XmlCatalogExtractorTest extends TestCase
{
    /** @var string[] */
    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function writeFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xmlcat').'.xml';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function extract(string $contents): array
    {
        return (new XmlCatalogExtractor)->extract($this->writeFixture($contents));
    }

    public function test_parses_yandex_yml_catalog_with_categories_and_availability(): void
    {
        $result = $this->extract(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <yml_catalog date="2026-01-01">
          <shop>
            <categories>
              <category id="1">Одежда</category>
              <category id="2">Обувь</category>
            </categories>
            <offers>
              <offer id="1001" available="true">
                <name>Платье красное</name>
                <price>2500</price>
                <currencyId>RUB</currencyId>
                <categoryId>1</categoryId>
                <vendorCode>DR-46-RED</vendorCode>
              </offer>
              <offer id="1002" available="false">
                <name>Кроссовки</name>
                <price>4000</price>
                <currencyId>RUB</currencyId>
                <categoryId>2</categoryId>
              </offer>
            </offers>
          </shop>
        </yml_catalog>
        XML);

        $this->assertSame('tabular', $result['kind']);
        $this->assertSame('yml', $result['meta']['xml_dialect']);
        $this->assertSame(2, $result['meta']['product_count']);

        $this->assertStringContainsString('Товар: Платье красное', $result['rows'][0]);
        $this->assertStringContainsString('Цена: 2500 RUB', $result['rows'][0]);
        $this->assertStringContainsString('Наличие: в наличии', $result['rows'][0]);
        $this->assertStringContainsString('Категория: Одежда', $result['rows'][0]);
        $this->assertStringContainsString('Артикул: DR-46-RED', $result['rows'][0]);

        $this->assertStringContainsString('Наличие: нет в наличии', $result['rows'][1]);
        // No vendorCode → falls back to the offer id.
        $this->assertStringContainsString('Артикул: 1002', $result['rows'][1]);
    }

    public function test_parses_google_merchant_rss_with_g_namespace(): void
    {
        $result = $this->extract(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
          <channel>
            <title>Shop</title>
            <item>
              <g:id>SKU-1</g:id>
              <g:title>Red Dress</g:title>
              <g:price>25.00 USD</g:price>
              <g:availability>in stock</g:availability>
              <g:product_type>Apparel &gt; Dresses</g:product_type>
            </item>
          </channel>
        </rss>
        XML);

        $this->assertSame('tabular', $result['kind']);
        $this->assertSame('merchant', $result['meta']['xml_dialect']);
        $this->assertSame(1, $result['meta']['product_count']);

        $row = $result['rows'][0];
        $this->assertStringContainsString('Товар: Red Dress', $row);
        $this->assertStringContainsString('Цена: 25.00 USD', $row);
        $this->assertStringContainsString('Наличие: в наличии', $row);
        $this->assertStringContainsString('Категория: Apparel > Dresses', $row);
        $this->assertStringContainsString('Артикул: SKU-1', $row);
    }

    public function test_parses_commerceml_товар_nodes(): void
    {
        $result = $this->extract(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <КоммерческаяИнформация>
          <Каталог>
            <Товары>
              <Товар>
                <Наименование>Стол письменный</Наименование>
                <Артикул>ST-100</Артикул>
                <Группа>Мебель</Группа>
              </Товар>
            </Товары>
          </Каталог>
        </КоммерческаяИнформация>
        XML);

        $this->assertSame('commerceml', $result['meta']['xml_dialect']);
        $this->assertSame(1, $result['meta']['product_count']);
        $this->assertStringContainsString('Товар: Стол письменный', $result['rows'][0]);
        $this->assertStringContainsString('Артикул: ST-100', $result['rows'][0]);
    }

    public function test_parses_generic_repeating_product_nodes(): void
    {
        $result = $this->extract(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <catalog>
          <product>
            <name>Widget</name>
            <price>10</price>
            <sku>W-1</sku>
          </product>
          <product>
            <name>Gadget</name>
            <price>20</price>
            <sku>G-1</sku>
          </product>
        </catalog>
        XML);

        $this->assertSame('generic', $result['meta']['xml_dialect']);
        $this->assertSame(2, $result['meta']['product_count']);
        $this->assertStringContainsString('Товар: Widget', $result['rows'][0]);
        $this->assertStringContainsString('Артикул: W-1', $result['rows'][0]);
        $this->assertStringContainsString('Товар: Gadget', $result['rows'][1]);
    }

    public function test_recovers_products_from_malformed_xml_without_crashing(): void
    {
        // Second offer's <price> is never closed — the document breaks partway,
        // but the first, well-formed offer must still be recovered.
        $result = $this->extract(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <yml_catalog>
          <shop>
            <offers>
              <offer id="1"><name>Good</name><price>100</price></offer>
              <offer id="2"><name>Broken</name><price>200
            </offers>
          </shop>
        </yml_catalog>
        XML);

        $this->assertSame('tabular', $result['kind']);
        $this->assertGreaterThanOrEqual(1, $result['meta']['product_count']);
        $this->assertStringContainsString('Товар: Good', $result['rows'][0]);
    }

    public function test_decodes_windows_1251_encoded_catalog(): void
    {
        $utf8 = <<<'XML'
        <?xml version="1.0" encoding="windows-1251"?>
        <yml_catalog>
          <shop>
            <offers>
              <offer id="7" available="true">
                <name>Платье синее</name>
                <price>1990</price>
                <currencyId>RUB</currencyId>
              </offer>
            </offers>
          </shop>
        </yml_catalog>
        XML;

        $bytes = mb_convert_encoding($utf8, 'WINDOWS-1251', 'UTF-8');

        $result = (new XmlCatalogExtractor)->extract($this->writeFixture($bytes));

        $this->assertSame('yml', $result['meta']['xml_dialect']);
        $this->assertSame(1, $result['meta']['product_count']);
        // Cyrillic must survive the transcode intact.
        $this->assertStringContainsString('Товар: Платье синее', $result['rows'][0]);
    }

    public function test_unknown_xml_falls_back_to_clean_text(): void
    {
        $result = $this->extract(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <note>
          <to>Owner</to>
          <body>Store hours are 9am to 6pm daily.</body>
        </note>
        XML);

        $this->assertSame('plain', $result['kind']);
        $this->assertSame('unknown', $result['meta']['xml_dialect']);
        $this->assertStringContainsString('Store hours are 9am to 6pm daily.', $result['text']);
    }
}
