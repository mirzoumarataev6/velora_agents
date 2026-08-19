<?php

namespace App\Events;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KnowledgeSourceProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public KnowledgeSource $source) {}
}
