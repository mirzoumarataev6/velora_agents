<?php

namespace App\Services\Bi\Ingestion;

use App\Services\Bi\BiIngestionException;

/**
 * One uploaded file, as ingestion needs to see it.
 *
 * Two verbs, and the first one streams. That is the entire point of this
 * interface: a 100MB export must be readable in constant memory, so a reader
 * never returns an array of rows and no caller may assume it can rewind one.
 * Everything downstream is written as a single forward pass.
 *
 * A workbook yields several sheets; a CSV yields exactly one. The caller does
 * not care which it is holding — that is what makes "one .xlsx becomes three
 * datasets" cost nothing at the call site.
 *
 * Every implementation throws BiIngestionException with a classified code rather
 * than letting a library exception escape, so no caller has to know what a
 * ZipArchive error or a Spout ReaderException means.
 */
interface TabularReader
{
    /**
     * The sheets in this file, in order. Lazily opened: iterating the returned
     * value is what starts reading.
     *
     * @return iterable<int, ReadSheet>
     *
     * @throws BiIngestionException
     */
    public function sheets(): iterable;

    /**
     * Release the file handle. Safe to call twice, and safe to call without
     * having finished iterating — an aborted ingest must not leak a descriptor.
     */
    public function close(): void;
}
