<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the normalized copy of a dataset: NDJSON, one row per line, in shards.
 *
 * This file is the reason correcting a wrong type inference is cheap. The original
 * upload is parsed exactly once, ever — and for a 60MB .xlsx that parse is the
 * expensive half of ingest — after which every re-materialization reads these
 * shards instead. An owner who fixes a date column gets a rebuilt table in
 * seconds rather than a second full spreadsheet parse.
 *
 * It is NDJSON rather than a second database table because this is bulk sequential
 * data with no queries run against it: a table would cost an index, a write-ahead
 * log and a tenant scope to store something only ever read start-to-finish.
 *
 * SHARDED because a shard is the unit of work a retry repeats. A worker killed
 * two thirds of the way through a 2M-row materialization resumes at a shard
 * boundary instead of starting over.
 *
 * Values are written as a positional JSON array, not an object. A 150-column file
 * would otherwise repeat its header names on every one of two million lines,
 * which for a typical export is more bytes of key than of data.
 */
class NdjsonShardWriter
{
    /** @var resource|null */
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

    /**
     * @param  array<int, mixed>  $row
     *
     * @throws BiIngestionException
     */
    public function write(array $row): void
    {
        $handle = $this->handle ?? $this->openShard();

        $line = json_encode(
            array_map($this->serialize(...), array_values($row)),
            // Unescaped unicode keeps a Cyrillic shard readable and roughly a
            // third smaller than \uXXXX escapes; INVALID_UTF8_SUBSTITUTE means one
            // byte that survived encoding detection cannot abort a 400,000-row
            // import at row 12.
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

    /**
     * Flush and release the current shard. Returns the number of shards written.
     */
    public function close(): int
    {
        $this->closeShard();

        return $this->shardIndex;
    }

    public function rowsWritten(): int
    {
        return $this->rowsTotal;
    }

    /**
     * Shard paths, in the order they must be read back.
     *
     * Zero-padded to six digits so lexical order IS numeric order — without the
     * padding, shard 10 would sort before shard 2 and a 2M-row dataset would be
     * materialized with its rows interleaved from the wrong shards.
     *
     * @return array<int, string>
     */
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

    /**
     * One scalar, in a form json_encode can carry losslessly.
     *
     * A DateTimeInterface becomes ISO-8601 with a `T`, which is what makes the
     * whole scheme work: a spreadsheet's own date cell reaches materialization as
     * an unambiguous string, and TypeCaster's ISO path — which tries the
     * time-bearing spellings before the date-only one — reads it back with its
     * clock intact.
     *
     * A float that is really an integer is written as an integer so 1500.0 does
     * not become "1500" in a text column.
     */
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

        // Nothing else should reach here — the readers only ever emit scalars,
        // nulls and dates — but an object serialized as its JSON text is a better
        // outcome than an aborted import.
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return resource
     */
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
