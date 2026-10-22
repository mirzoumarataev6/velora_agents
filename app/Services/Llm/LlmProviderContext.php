<?php

namespace App\Services\Llm;

use App\Models\Team;

class LlmProviderContext
{
    protected ?Team $team = null;

    public function for(?Team $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function current(): ?Team
    {
        return $this->team;
    }
}
