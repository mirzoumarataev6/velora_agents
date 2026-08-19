<?php

namespace App\Models;

use App\Policies\KnowledgeChunkPolicy;
use Database\Factories\KnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content', 'embedding', 'embedding_model', 'token_count', 'chunk_index'])]
#[UsePolicy(KnowledgeChunkPolicy::class)]
class KnowledgeChunk extends Model
{
    /** @use HasFactory<KnowledgeChunkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'token_count' => 'integer',
            'chunk_index' => 'integer',
        ];
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class);
    }

    /**
     * The text actually sent to the embedding model — the chunk prefixed with
     * the name its owner gave the source.
     *
     * That name is often the only place the topic is stated in words a customer
     * would use. A source called "учителя" holding "Воситов Равшан — веб
     * разработка, …" embeds as a list of people and technologies with the word
     * "учитель" nowhere in it, so "расскажите про учителей" scored 0.167 against
     * it — below every unrelated page of the website. Folding the label in
     * raises that same pair to 0.372 and puts it first.
     *
     * Only the embedding input is affected; `content` stays clean for display
     * and for the prompt, which gets the source name in its own header anyway.
     */
    public function embeddingText(): string
    {
        $name = trim((string) ($this->knowledgeSource?->name ?? ''));

        // A URL as a title is noise, not a topic — skip those rather than
        // embedding "https://startcoding.tj/" into every chunk of the site.
        if ($name === '' || filter_var($name, FILTER_VALIDATE_URL) !== false) {
            return $this->content;
        }

        return $name."\n\n".$this->content;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
