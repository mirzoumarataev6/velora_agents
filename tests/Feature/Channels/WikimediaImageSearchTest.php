<?php

namespace Tests\Feature\Channels;

use App\Services\Channels\WikimediaImageSearch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikimediaImageSearchTest extends TestCase
{
    public function test_search_failure_is_not_reported_as_an_empty_search(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response([], 503)]);
        $this->expectException(\RuntimeException::class);
        app(WikimediaImageSearch::class)->search('OMEN 16', 1, 'laptop');
    }

    public function test_empty_successful_search_returns_no_images(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['batchcomplete' => ''], 200)]);
        $this->assertSame([], app(WikimediaImageSearch::class)->search('OMEN 16', 1, 'laptop'));
    }

    public function test_search_returns_at_most_three_attributed_licensed_images(): void
    {
        $pages = [];
        for ($id = 1; $id <= 6; $id++) {
            $pages[(string) $id] = [
                'title' => "File:Laptop {$id}.jpg",
                'imageinfo' => [[
                    // This is the hostname returned by Commons' live API for
                    // generated previews, rather than its original-file host.
                    'thumburl' => "https://thumb.wikimedia.org/wikipedia/commons/thumb/{$id}.jpg",
                    'extmetadata' => [
                        'Artist' => ['value' => 'Test photographer'],
                        'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                        'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
                    ],
                ]],
            ];
        }
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => $pages]], 200)]);

        $images = app(WikimediaImageSearch::class)->search('laptop', 10);

        $this->assertCount(3, $images);
        $this->assertStringStartsWith('https://thumb.wikimedia.org/', $images[0]['url']);
        $this->assertStringContainsString('Test photographer', $images[0]['caption']);
        $this->assertStringContainsString('CC BY-SA 4.0', $images[0]['caption']);
        Http::assertSent(fn ($request) => $request['gsrsearch'] === 'filetype:bitmap laptop'
            && $request['gsrlimit'] <= 10);
    }

    public function test_search_skips_images_without_reuse_license_metadata(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
            ['title' => 'File:No license.jpg', 'imageinfo' => [[
                'url' => 'https://upload.wikimedia.org/no-license.jpg', 'extmetadata' => [],
            ]]],
        ]]], 200)]);

        $this->assertSame([], app(WikimediaImageSearch::class)->search('item'));
    }

    public function test_search_rejects_an_unrelated_title_when_a_specific_model_was_requested(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
            ['title' => 'File:Omen-NOA-Geneva.jpg', 'imageinfo' => [[
                'thumburl' => 'https://thumb.wikimedia.org/omen-art.jpg',
                'extmetadata' => [
                    'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
                ],
            ]]],
            ['title' => 'File:HP OMEN 16 laptop.jpg', 'imageinfo' => [[
                'thumburl' => 'https://thumb.wikimedia.org/hp-omen-16.jpg',
                'extmetadata' => [
                    'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
                ],
            ]]],
            ['title' => 'File:Profane Omen MTR 20110618 16.jpg', 'imageinfo' => [[
                'thumburl' => 'https://thumb.wikimedia.org/omen-guitarist.jpg',
                'extmetadata' => [
                    'LicenseShortName' => ['value' => 'CC BY-SA 3.0'],
                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/3.0/'],
                ],
            ]]],
        ]]], 200)]);

        $images = app(WikimediaImageSearch::class)->search('OMEN 16', 3, 'laptop');

        $this->assertSame(['https://thumb.wikimedia.org/hp-omen-16.jpg'], array_column($images, 'url'));
    }
}
