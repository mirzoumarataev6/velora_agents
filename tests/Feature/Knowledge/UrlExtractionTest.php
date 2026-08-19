<?php

namespace Tests\Feature\Knowledge;

use App\Services\Knowledge\ChunkerService;
use App\Services\Knowledge\KnowledgeExtractionService;
use RuntimeException;
use Tests\TestCase;

class UrlExtractionTest extends TestCase
{
    protected function extractor(): KnowledgeExtractionService
    {
        return app(KnowledgeExtractionService::class);
    }

    protected function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/{$name}"));
    }

    public function test_it_captures_the_footer_address_phone_and_email(): void
    {
        $result = $this->extractor()->parseHtmlContent($this->fixture('business-landing.html'));
        $text = $result['text'];

        // The street address (which lived only in the footer <address> and JSON-LD)
        // must survive extraction — the old code stripped <footer> entirely.
        $this->assertStringContainsString('Рудаки, 42', $text);
        $this->assertStringContainsString('734001', $text);
        $this->assertStringContainsString('Душанбе', $text);

        // Contact details are surfaced structurally as well.
        $this->assertContains('hello@teplyugol.tj', $result['meta']['emails']);
        $this->assertNotEmpty($result['meta']['phones']);
        $this->assertNotEmpty($result['meta']['addresses']);

        // And a CONTACT INFO summary is prepended for retrieval.
        $this->assertStringContainsString('CONTACT INFO', $text);
    }

    public function test_it_keeps_body_content_beyond_the_address(): void
    {
        $text = $this->extractor()->parseHtmlContent($this->fixture('business-landing.html'))['text'];

        $this->assertStringContainsString('обжариваем кофе', $text);
        $this->assertStringContainsString('Спешелти-кофе', $text); // a list item
        $this->assertStringContainsString('08:00 – 22:00', $text);  // a definition list value
    }

    public function test_the_full_address_lands_in_a_single_chunk(): void
    {
        $extracted = $this->extractor()->parseHtmlContent($this->fixture('business-landing.html'));

        $chunks = (new ChunkerService)->chunk($extracted, [
            'chunk_size_tokens' => 700,
            'chunk_overlap_tokens' => 100,
            'spreadsheet_rows_per_chunk' => 20,
        ]);

        // Exactly one chunk should carry the complete address block — never split
        // across a boundary. This is the concrete, deterministic form of "a query
        // for адрес/address hits the right chunk".
        $chunksWithStreet = array_filter($chunks, fn ($c) => str_contains($c, 'Рудаки, 42'));
        $this->assertCount(1, $chunksWithStreet);

        $addressChunk = array_values($chunksWithStreet)[0];
        $this->assertStringContainsString('734001', $addressChunk);
        $this->assertStringContainsString('Душанбе', $addressChunk);
        $this->assertStringContainsString('CONTACT INFO', $addressChunk);
    }

    public function test_javascript_rendered_pages_are_flagged_with_a_helpful_error(): void
    {
        $spaHtml = <<<'HTML'
        <!DOCTYPE html>
        <html><head><title>Loading…</title></head>
        <body>
            <div id="app"></div>
            <script src="/app.js"></script>
            <script src="/vendor.js"></script>
            <script>window.__INITIAL_STATE__ = {};</script>
        </body></html>
        HTML;

        try {
            $this->extractor()->parseHtmlContent($spaHtml);
            $this->fail('Expected a RuntimeException for a JavaScript-rendered page.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('JavaScript-rendered', $e->getMessage());
        }
    }

    public function test_plain_static_pages_without_contact_info_still_extract(): void
    {
        $html = '<html><head><title>About</title></head><body><main><h1>About us</h1>'
            .'<p>We have been building tools for small businesses since 2019.</p></main></body></html>';

        $result = $this->extractor()->parseHtmlContent($html);

        $this->assertStringContainsString('building tools for small businesses', $result['text']);
        // No contact info → no CONTACT INFO block, and that is fine.
        $this->assertStringNotContainsString('CONTACT INFO', $result['text']);
    }
}
