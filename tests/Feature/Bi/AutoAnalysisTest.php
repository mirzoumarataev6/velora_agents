<?php

namespace Tests\Feature\Bi;

use App\Models\BiDashboard;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Dashboard\WidgetSpecBuilder;
use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

class AutoAnalysisTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function ready(BiSource $source): BiSource
    {
        foreach (app(SourceNormalizer::class)->normalize($source) as $dataset) {
            app(DatasetMaterializer::class)->materialize($dataset);
        }

        return $source->fresh();
    }

    public function test_sales_file_generates_a_valid_dashboard_without_a_wizard(): void
    {
        $owner = User::factory()->create();
        $rows = [['Дата', 'Регион', 'Товар', 'Выручка', 'Количество']];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [sprintf('%02d.%02d.2026', 1 + $i % 27, 1 + intdiv($i, 15)), $i % 2 ? 'Душанбе' : 'Худжанд', $i % 3 ? 'Куртка' : 'Джинсы', (string) (1000 + $i * 30), (string) (1 + $i % 4)];
        }
        $source = $this->ready($this->csvSource($owner, $rows));

        $this->actingAs($owner)->post(route('bi.sources.analyze', $source))->assertRedirect();

        $board = BiDashboard::firstOrFail();
        $this->assertSame($source->id, $board->bi_source_id);
        $widgets = $board->widgets()->with('dataset.columns')->get();
        $this->assertGreaterThanOrEqual(7, $widgets->count());
        $this->assertContains(WidgetType::Area, $widgets->pluck('type')->all());
        foreach ($widgets as $widget) {
            $this->assertNotNull(app(WidgetSpecBuilder::class)->build($widget, $board));
        }
    }

    public function test_text_only_file_still_generates_counts_and_a_useful_table(): void
    {
        $owner = User::factory()->create();
        $source = $this->ready($this->csvSource($owner, [
            ['Отдел', 'Комментарий'], ['Продажи', 'Готово'], ['Поддержка', 'Ожидает'], ['Продажи', 'Готово'],
        ]));

        $this->actingAs($owner)->post(route('bi.sources.analyze', $source))->assertRedirect();
        $types = BiDashboard::firstOrFail()->widgets()->get()->pluck('type')->map(fn (WidgetType $type) => $type->value)->all();
        $this->assertContains('kpi', $types);
        $this->assertContains('table', $types);
        $this->assertNotContains('area', $types);
    }

    public function test_largest_sheet_is_selected_and_other_owner_cannot_generate_from_it(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $source = $this->ready($this->spreadsheetSource($owner, [
            'Small' => [['Регион', 'Выручка'], ['Душанбе', 10]],
            'Large' => [['Регион', 'Выручка'], ['Душанбе', 20], ['Худжанд', 30], ['Бохтар', 40]],
        ]));

        $this->actingAs($other)->post(route('bi.sources.analyze', $source))->assertForbidden();
        $this->assertSame(0, BiDashboard::count());
        $this->actingAs($owner)->post(route('bi.sources.analyze', $source))->assertRedirect();
        $this->assertSame('Large', BiDashboard::firstOrFail()->mainDataset->sheet_name);

        $small = $source->datasets()->where('sheet_name', 'Small')->firstOrFail();
        $this->actingAs($owner)->post(route('bi.sources.analyze', $source), ['dataset_id' => $small->id])->assertRedirect();
        $this->assertSame('Small', BiDashboard::latest('id')->firstOrFail()->mainDataset->sheet_name);

        $foreignSource = $this->ready($this->csvSource($other, [['Регион', 'Выручка'], ['Худжанд', '10']]));
        $foreignDataset = $foreignSource->datasets()->firstOrFail();
        $this->actingAs($owner)->post(route('bi.sources.analyze', $source), ['dataset_id' => $foreignDataset->id])->assertNotFound();
    }
}
