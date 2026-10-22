<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;

class NdjsonShardWriter
{
    private $handle = null;

    private int $shardIndex = 0;

    private int $rowsInShard = 0;

    private int $rowsTotal = 0;

    private readonly int $rowsPerShard;

    public function __construct(
        private readonly string $directory,
        private readonly string $disk = 'local',
        ?int $rowsPerShard = null,
    ) {
        $this->rowsPerShard = max(1000, $rowsPerShard ?? (int) config('agent.bi.ingest.shard_rows', 50000));
    }

    public function write(array $row): void
    {
        $handle = $this->handle ?? $this->openShard();

        $line = json_encode(
            array_map($this->serialize(...), array_values($row)),

            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($line === false) {
            throw new BiIngestionException(
                BiIngestionException::CORRUPT_FILE,
                'A row could not be serialized: '.json_last_error_msg()
            );
        }

        if (@fwrite($handle, $line."\n") === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The normalized copy could not be written to disk — it may be full.'
            );
        }

        $this->rowsTotal++;

        if (++$this->rowsInShard >= $this->rowsPerShard) {
            $this->closeShard();
        }
    }

    public function close(): int
    {
        $this->closeShard();

        return $this->shardIndex;
    }

    public function rowsWritten(): int
    {
        return $this->rowsTotal;
    }

    public static function shardPaths(string $directory, int $count): array
    {
        $paths = [];

        for ($i = 0; $i < $count; $i++) {
            $paths[] = $directory.'/'.self::shardName($i);
        }

        return $paths;
    }

    public static function shardName(int $index): string
    {
        return sprintf('%06d.ndjson', $index);
    }

    private function serialize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return $value === floor($value) && abs($value) < 1.0e15 ? (int) $value : $value;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function openShard()
    {
        $disk = Storage::disk($this->disk);
        $disk->makeDirectory($this->directory);

        $path = $disk->path($this->directory.'/'.self::shardName($this->shardIndex));

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'A normalized shard could not be opened for writing.'
            );
        }

        $this->rowsInShard = 0;

        return $this->handle = $handle;
    }

    private function closeShard(): void
    {
        if ($this->handle === null) {
            return;
        }

        fclose($this->handle);
        $this->handle = null;
        $this->shardIndex++;
        $this->rowsInShard = 0;
    }
}
