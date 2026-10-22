<?php

namespace App\Services\Bi\Ingestion;

interface TabularReader
{
    public function sheets(): iterable;

    public function close(): void;
}
