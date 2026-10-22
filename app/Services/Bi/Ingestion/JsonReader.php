<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;
use Generator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Throwable;

final class JsonReader implements TabularReader
{
    private const HEADER_SAMPLE = 200;

    public const KEY_COLUMN = '_key';

    public function __construct(
        private readonly string $path,
        private readonly bool $isNdjson,
    ) {}

    public function sheets(): iterable
    {
        [$header, $keyed] = $this->discoverHeader();

        yield new ReadSheet('', 0, fn (): Generator => $this->readRows($header, $keyed));
    }

    public function close(): void {}

    private function discoverHeader(): array
    {
        $keys = [];
        $seen = 0;
        $sawScalar = false;
        $keyed = false;
        $expectedIndex = 0;

        foreach ($this->items() as $key => $item) {

            if ($key !== $expectedIndex++) {
                $keyed = true;
            }

            if ($seen++ >= self::HEADER_SAMPLE) {
                break;
            }

            if (! is_array($item) && ! is_object($item)) {
                $sawScalar = true;

                continue;
            }

            foreach (array_keys($this->flatten($item)) as $flatKey) {
                $keys[(string) $flatKey] = true;
            }
        }

        if ($seen === 0) {
            throw new BiIngestionException(
                BiIngestionException::NO_ROWS,
                'The JSON file contains no values to import.'
            );
        }

        if ($keys === []) {
            if ($sawScalar) {

                return [$keyed ? [self::KEY_COLUMN, 'value'] : ['value'], $keyed];
            }

            throw new BiIngestionException(
                BiIngestionException::NOT_TABULAR,
                'The JSON file has no object keys that could become columns.'
            );
        }

        $header = array_keys($keys);

        return [$keyed ? [self::KEY_COLUMN, ...$header] : $header, $keyed];
    }

    private function readRows(array $header, bool $keyed): Generator
    {

        yield $header;

        $valueColumns = $keyed ? array_slice($header, 1) : $header;

        foreach ($this->items() as $key => $item) {
            $prefix = $keyed ? [$key] : [];

            if (! is_array($item) && ! is_object($item)) {
                yield array_pad([...$prefix, $item], count($header), null);

                continue;
            }

            $row = $this->flatten($item);
            $values = $prefix;

            foreach ($valueColumns as $column) {
                $values[] = $row[$column] ?? null;
            }

            yield $values;
        }
    }

    private function flatten(mixed $item): array
    {
        $flat = [];

        foreach ((array) $item as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $flat[(string) $key] = $value;

                continue;
            }

            $nested = (array) $value;

            if ($nested === [] || array_is_list($nested)) {
                $flat[(string) $key] = $this->encode($nested);

                continue;
            }

            foreach ($nested as $innerKey => $innerValue) {
                $flat[$key.'.'.$innerKey] = is_scalar($innerValue) || $innerValue === null
                    ? $innerValue
                    : $this->encode($innerValue);
            }
        }

        return $flat;
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function items(): iterable
    {
        if ($this->isNdjson) {
            return $this->ndjsonItems();
        }

        return $this->jsonItems();
    }

    private function jsonItems(): Generator
    {
        try {

            $items = Items::fromFile($this->path, ['decoder' => new ExtJsonDecoder(true)]);

            yield from $items;
        } catch (Throwable $e) {
            throw $this->classify($e);
        }
    }

    private function ndjsonItems(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new BiIngestionException(
                BiIngestionException::FILE_UNREADABLE,
                'The uploaded file could not be opened.'
            );
        }

        try {
            $index = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (json_last_error() === JSON_ERROR_NONE) {

                    yield $index++ => $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function classify(Throwable $e): BiIngestionException
    {
        if ($e instanceof BiIngestionException) {
            return $e;
        }

        return new BiIngestionException(
            BiIngestionException::CORRUPT_FILE,
            'The JSON file could not be parsed: '.$e->getMessage(),
            $e,
        );
    }
}
