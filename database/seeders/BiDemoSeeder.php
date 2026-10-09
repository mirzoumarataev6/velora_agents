<?php

namespace Database\Seeders;

use App\Models\BiDashboard;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Dashboard\AutoAnalysisGenerator;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * A demo BI source, dataset and dashboard for the owner.
 *
 * Runs the REAL ingest pipeline — a CSV is written to the disk, normalized,
 * inferred and materialized — rather than inserting rows into `bi_datasets` and
 * a hand-made `bi_ds_*` table. That is the point: a seeded board has to exercise
 * the same code an upload does, or the screenshots show a surface that does not
 * exist and a local database drifts from what the product actually produces.
 *
 * The data is deliberately a bit messy. One revenue cell says "по запросу" and
 * one region is blank, so the seeded board shows what the module does with
 * imperfect data — the inference note, the blank bucket, the nulls-last sort —
 * instead of a tidy fiction that hides every behaviour worth looking at.
 */
class BiDemoSeeder extends Seeder
{
    public function __construct(private readonly ?string $ownerEmail = null) {}

    public function run(): void
    {
        $owner = $this->ownerEmail
            ? User::where('email', $this->ownerEmail)->first()
            : User::orderBy('id')->first();

        if (! $owner) {
            $this->command?->error('No owner user found — register an account first.');

            return;
        }

        $this->purge($owner);

        $source = $this->stageCsv($owner);

        $datasets = app(SourceNormalizer::class)->normalize($source);

        foreach ($datasets as $dataset) {
            app(DatasetMaterializer::class)->materialize($dataset);
        }

        // The SOURCE's own status transition lives in NormalizeSourceJob, not in
        // the normalizer — so a seeder that calls the services directly has to
        // make it itself, or the row sits at `pending` forever and the screen
        // shows a spinner next to a dataset that is plainly finished.
        $source->forceFill([
            'status' => BiSource::STATUS_READY,
            'normalized_at' => now(),
        ])->save();

        $dataset = $datasets[0]->fresh();

        // CLI seeders run in English even when the product's primary locale is
        // Russian. Dashboard titles are persisted, so generate them in the
        // locale the demo owner will actually see.
        $previousLocale = App::getLocale();
        try {
            App::setLocale('ru');
            app(AutoAnalysisGenerator::class)->generate($owner, $source->fresh());
        } finally {
            App::setLocale($previousLocale);
        }

        $this->command?->info("BI demo: dataset [{$dataset->name}] with {$dataset->row_count} rows, one dashboard.");
    }

    /**
     * Remove what a previous run made.
     *
     * Deleting the SOURCE is enough and is the only correct way to do it: the
     * observer chain (BiSourceObserver → BiDatasetObserver → DropDatasetTableJob)
     * is what drops the runtime `bi_ds_*` tables and the shards, and a SQL
     * cascade cannot. Bypassing it here would leave an orphaned analytical
     * table behind on every reseed.
     */
    protected function purge(User $owner): void
    {
        BiDashboard::ownedBy($owner)->whereIn('name', ['Продажи', 'Продажи · Таджикистан'])->get()->each->delete();

        BiSource::ownedBy($owner)->whereIn('name', ['Продажи 2026', 'Продажи · Таджикистан'])->get()->each->delete();
    }

    protected function stageCsv(User $owner): BiSource
    {
        $rows = [
            ['Дата заказа', 'Регион', 'Категория', 'Товар', 'Менеджер', 'Количество', 'Цена', 'Выручка', 'Оплачен'],
        ];

        $regions = ['Душанбе', 'Худжанд', 'Бохтар', 'Куляб', 'Хорог', 'Пенджикент'];
        $managers = ['Фируза К.', 'Далер С.', 'Нигина М.', 'Азиз Р.'];
        $products = [
            ['Одежда', 'Джинсы классические', 320], ['Обувь', 'Кроссовки', 490],
            ['Одежда', 'Рубашка', 220], ['Одежда', 'Платье', 410],
            ['Аксессуары', 'Кепка', 130], ['Одежда', 'Свитер', 360],
            ['Одежда', 'Куртка зимняя', 890], ['Обувь', 'Ботинки', 750],
            ['Аксессуары', 'Сумка', 540], ['Аксессуары', 'Ремень', 180],
        ];

        // Deterministic, so the screenshots do not change between runs.
        mt_srand(20261002);

        for ($i = 0; $i < 2000; $i++) {
            $date = (new \DateTimeImmutable('2025-04-01'))->modify('+'.(($i * 37) % 548).' days');
            $product = $products[($i * 7 + intdiv($i, 19)) % count($products)];
            $quantity = 1 + ($i % 6);
            $price = $product[2] + (($i * 13) % 40);

            // Every twenty-third row is the kind of cell a real export
            // contains: a price that was never filled in.
            $revenue = $i % 101 === 0
                ? 'по запросу'
                : number_format($quantity * $price * (1 + (($i % 5) - 2) / 100), 2, '.', '');

            $rows[] = [
                $date->format('d.m.Y'),
                // A few missing regions are retained as an honest blank bucket.
                $i % 97 === 0 ? '' : $regions[($i * 7 + intdiv($i, 13)) % count($regions)],
                $product[0],
                $product[1],
                $managers[$i % count($managers)],
                $quantity,
                $price,
                $revenue,
                $i % 3 === 0 ? 'нет' : 'да',
            ];
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn ($cell) => str_contains((string) $cell, ',') ? '"'.$cell.'"' : (string) $cell,
                $row
            ));
        }

        $content = implode("\r\n", $lines)."\r\n";

        $source = new BiSource([
            'kind' => 'file',
            'name' => 'Продажи · Таджикистан',
            'original_filename' => 'Продажи_Таджикистан.csv',
            'extension' => 'csv',
            'mime_type' => 'text/csv',
            'bytes' => strlen($content),
            'status' => BiSource::STATUS_PENDING,
        ]);

        // `user_id` is not fillable — it is the tenant key, and the whole
        // module keeps it off the mass-assignable list on purpose.
        $source->user_id = $owner->id;
        $source->save();

        // The path is composed from two integers we own — no request-supplied
        // segment anywhere in it, exactly as SourceController builds it.
        $path = "bi/{$owner->id}/sources/{$source->id}/original.csv";
        Storage::disk('local')->put($path, $content);

        $source->forceFill(['disk_path' => $path])->save();

        return $source->fresh();
    }
}
