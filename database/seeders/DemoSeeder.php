<?php

namespace Database\Seeders;

use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\KnowledgeSource;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Haggling\HagglingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Five presentation agents from Khujand, with a month of chats, leads and
 * customer ratings behind them.
 *
 * Re-runnable by construction: every demo agent has a fixed `demo-*` slug, and
 * a run starts by deleting those agents — the foreign keys cascade to their
 * channels, conversations, messages, leads, catalog and knowledge. Nothing
 * outside those slugs is written, so the owner's real agents are untouched.
 *
 * The script itself lives in data/demo.php; this class only turns it into rows
 * with believable timestamps and metadata.
 */
class DemoSeeder extends Seeder
{
    /** The real shop whose catalog the Олами Компютерхо demo reuses. */
    protected const PIXELSHOP_SLUG = 'olami-kompiuterxo';

    protected const TIMEZONE = 'Asia/Dushanbe';

    /** Share of agent replies that carry a customer rating. */
    protected const RATED_SHARE = 0.30;

    /** Owner the agents belong to; null means the first user. */
    public ?string $ownerEmail = null;

    /** @var array<int, array{message: Message, rating: ?string, eligible: bool}> */
    protected array $replies = [];

    /** @var array<int, KnowledgeSource> */
    protected array $sources = [];

    protected int $externalId = 0;

    public function run(): void
    {
        $data = require __DIR__.'/data/demo.php';
        $agents = $data['agents'];

        $owner = $this->ownerEmail
            ? User::where('email', $this->ownerEmail)->first()
            : User::orderBy('id')->first();

        if (! $owner) {
            $this->command?->error('No owner user found — register an account first (or pass --user=email).');

            return;
        }

        // Same "random" gaps, response times and ratings on every run.
        mt_srand(20260914);

        DB::transaction(function () use ($agents, $data, $owner) {
            $this->purge(array_column($agents, 'slug'));

            foreach ($agents as $spec) {
                $this->seedAgent($owner, $spec);
            }

            $this->seedPixelShopExtra($data['pixelshop_extra']);

            $this->applyRatings();
        });

        // After commit: the knowledge pipeline (extract → chunk → embed) runs on
        // the queue, exactly as if the owner had pasted the text in the panel.
        foreach ($this->sources as $source) {
            ExtractTextJob::dispatch($source);
        }

        $this->summary($owner, array_column($agents, 'slug'));
    }

    /** @param  array<int, string>  $slugs */
    protected function purge(array $slugs): void
    {
        foreach (Agent::whereIn('slug', $slugs)->get() as $agent) {
            Storage::disk('local')->deleteDirectory("knowledge/{$agent->id}");
            $agent->delete();
        }
    }

    /**
     * The real PixelShop agent (Олами Компютерхо's live counterpart) is never
     * purged or recreated — it is the owner's actual data. This only adds a
     * handful of `demo_v2`-tagged conversations on top of it, and only ever
     * touches rows carrying that marker, so a real conversation or lead the
     * owner already has is never at risk.
     *
     * @param  array<int, array<string, mixed>>  $conversations
     */
    protected function seedPixelShopExtra(array $conversations): void
    {
        $agent = Agent::where('slug', self::PIXELSHOP_SLUG)->first();

        if (! $agent) {
            return;
        }

        $this->purgeDemoV2($agent);

        $createdAt = now();

        $channels = [
            'telegram' => $agent->channels()->where('type', 'telegram')->first()
                ?? $this->seedChannel($agent, 'telegram', 'pixelshop_khujand_bot', $createdAt),
            'greenapi' => $agent->channels()->where('type', 'greenapi')->first()
                ?? $this->seedChannel($agent, 'greenapi', '+992 92 666 70 80', $createdAt),
        ];

        foreach ($conversations as $spec) {
            $this->seedConversation($agent, $channels, $spec, 'demo_v2');
        }
    }

    /**
     * Deletes only the `demo_v2`-tagged conversations (and any leads pointing
     * at them) that belong to a live, non-demo agent — leaving every real
     * conversation, lead and channel of that agent untouched.
     */
    protected function purgeDemoV2(Agent $agent): void
    {
        $conversationIds = Message::where('external_message_id', 'like', 'demo_v2-%')
            ->whereIn('conversation_id', $agent->conversations()->select('id'))
            ->distinct()
            ->pluck('conversation_id');

        Lead::whereIn('conversation_id', $conversationIds)->delete();
        Conversation::whereIn('id', $conversationIds)->delete();
    }

    /** @param  array<string, mixed>  $spec */
    protected function seedAgent(User $owner, array $spec): void
    {
        $createdAt = now()->subDays(33)->setTime(6, 30);
        $haggling = $spec['haggling'];

        $agent = Agent::factory()->for($owner)->create([
            'name' => $spec['name'],
            'slug' => $spec['slug'],
            'description' => $spec['description'],
            'status' => 'active',
            'tone' => $spec['tone'],
            'proactivity' => $spec['proactivity'],
            'custom_system_prompt' => $spec['prompt'],
            'rules_instructions' => $spec['rules'],
            'response_format' => [
                'greeting_message' => $spec['greeting'],
                'fallback_message' => null,
                'max_length' => 600,
                'use_emojis' => true,
            ],
            'behavior' => ['escalation' => 'balanced', 'on_not_found' => 'suggest_alternatives'],
            'haggling_enabled' => $haggling !== null,
            'haggling_level' => $haggling['level'] ?? 'moderate',
            'haggle_min_price_percent' => $haggling['percent'] ?? 100,
            'haggling_instructions' => $haggling['instructions'] ?? null,
            'product_source' => 'dataset',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $channels = [];
        foreach ($spec['channels'] as $type => $handle) {
            $channels[$type] = $this->seedChannel($agent, $type, $handle, $createdAt);
        }

        $this->seedKnowledge($agent, $spec, $createdAt);

        if (($spec['catalog'] ?? null) === 'pixelshop') {
            $this->seedCatalog($agent, $spec['fallback_catalog'], $createdAt);
        }

        foreach ($spec['conversations'] as $conversation) {
            $this->seedConversation($agent, $channels, $conversation, ! empty($conversation['v2']) ? 'demo_v2' : 'demo');
        }

        foreach ($spec['leads'] as $lead) {
            $at = $this->localTime($lead['days'], $lead['at']);
            $this->createLead($agent, null, $lead, $lead['source'], $at);
        }
    }

    protected function seedChannel(Agent $agent, string $type, string $handle, $createdAt): Channel
    {
        $factory = Channel::factory()->for($agent);

        [$factory, $settings] = match ($type) {
            'whatsapp' => [$factory->whatsapp(), ['display_phone_number' => $handle, 'verified_name' => $agent->name]],
            'greenapi' => [$factory->greenApi(), ['display_phone_number' => $handle, 'state_instance' => 'authorized', 'webhook_registered' => true, 'webhook_error' => null]],
            'instagram' => [$factory->instagram(), ['ig_username' => $handle, 'ig_name' => $agent->name]],
            default => [$factory, ['bot_username' => $handle, 'bot_first_name' => $agent->name, 'webhook_secret' => Str::random(48)]],
        };

        return $factory->create([
            'settings' => $settings,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @param  array<string, mixed>  $spec */
    protected function seedKnowledge(Agent $agent, array $spec, $createdAt): void
    {
        $path = "knowledge/{$agent->id}/sources/".Str::uuid().'.txt';
        Storage::disk('local')->put($path, $spec['knowledge']);

        $this->sources[] = KnowledgeSource::factory()->for($agent)->create([
            'type' => 'text',
            'name' => $spec['knowledge_name'],
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * The real shop's imported spreadsheet, copied row for row when it exists,
     * so the demo quotes the same prices the live bot does.
     *
     * @param  array<int, array{0: string, 1: int, 2: string, 3: array<string, string>}>  $fallback
     */
    protected function seedCatalog(Agent $agent, array $fallback, $createdAt): void
    {
        $source = Agent::where('slug', self::PIXELSHOP_SLUG)->first()?->dataset;

        $rows = $source
            ? $source->products()->orderBy('position')->get()
                ->map(fn (DatasetProduct $p) => [$p->name, (float) $p->price, $p->category, $p->extra ?? []])
                ->all()
            : $fallback;

        $dataset = Dataset::factory()->for($agent)->create([
            'original_filename' => $source?->original_filename ?? 'olami_katalog.xlsx',
            'row_count' => count($rows),
            'mapping' => $source?->mapping ?? ['name' => 1, 'category' => 2, 'price' => 6, 'extra' => [3, 4, 5, 7]],
            'columns' => $source?->columns ?? ['№', 'Модель', 'Категория', 'Процессор', 'ОЗУ', 'Память', 'Цена (сомони)', 'видеокарта'],
            'imported_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach (array_values($rows) as $position => [$name, $price, $category, $extra]) {
            DatasetProduct::factory()->for($dataset)->create([
                'agent_id' => $agent->id,
                'name' => $name,
                'price' => $price,
                'stock' => null,
                'category' => $category,
                'description' => null,
                'sku' => null,
                'extra' => $extra ?: null,
                'position' => $position,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    /**
     * @param  array<string, Channel>  $channels
     * @param  array<string, mixed>  $spec
     */
    protected function seedConversation(Agent $agent, array $channels, array $spec, string $idPrefix = 'demo'): Conversation
    {
        $channel = $channels[$spec['channel']];
        $at = $this->localTime($spec['days'], $spec['at']);

        $conversation = Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_id' => $this->externalUserId($spec['channel']),
            'external_user_name' => $spec['customer'],
            'status' => 'ai',
            'last_message_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $lastQuestion = '';

        foreach ($spec['turns'] as $i => $turn) {
            [$role, $content] = $turn;
            $flags = $turn[2] ?? [];

            $at = $i === 0 ? $at : match ($role) {
                // Reading the answer and typing the next question.
                'u' => $at->addSeconds(mt_rand(25, 190)),
                // The owner noticed and answered by hand.
                'o' => $at->addMinutes(mt_rand(12, 45)),
                default => $at,
            };

            if ($role === 'u') {
                $lastQuestion = $content;
                $this->message($conversation, 'user', $content, $at, null, [
                    'external_message_id' => $this->nextExternalId($idPrefix),
                    'source_type' => isset($flags['voice']) ? 'voice' : 'text',
                    'voice_duration' => $flags['voice'] ?? null,
                ]);

                continue;
            }

            if ($role === 'o') {
                $this->message($conversation, 'operator', $content, $at);

                continue;
            }

            $totalMs = mt_rand(2300, 7400) + (int) (mb_strlen($content) * 2.5);
            $at = $at->addMilliseconds($totalMs + mt_rand(300, 900));

            $lead = null;
            if (! empty($flags['lead']) && isset($spec['lead'])) {
                $lead = $this->createLead($agent, $conversation, $spec['lead'], $spec['channel'], $at);
            }

            $message = $this->message($conversation, 'assistant', $content, $at, $this->replyMeta($totalMs, $flags, $lastQuestion, $lead, $idPrefix === 'demo_v2'));

            $this->replies[] = [
                'message' => $message,
                'rating' => isset($flags['r']) ? ($flags['r'] === 'up' ? Message::RATING_UP : Message::RATING_DOWN) : null,
                // A reply the agent itself flagged as a non-answer is never
                // handed a thumbs-up by the random fill below.
                'eligible' => ! isset($flags['miss']),
            ];
        }

        $unread = ! empty($spec['unread']);
        $readAt = $at->addMinutes(mt_rand(8, 180));

        $conversation->forceFill([
            'status' => $spec['status'],
            'last_message_at' => $at,
            'operator_read_at' => $unread ? null : ($readAt->isFuture() ? now() : $readAt),
            'updated_at' => $at,
        ])->save();

        return $conversation;
    }

    /**
     * What the reply pipeline stamps on a real assistant turn — the fields the
     * dashboard reads (timings, catalog demand, non-answers, captured leads).
     *
     * @param  array<string, mixed>  $flags
     * @return array<string, mixed>
     */
    protected function replyMeta(int $totalMs, array $flags, string $question, ?Lead $lead, bool $isV2 = false): array
    {
        $queries = array_map(fn ($q) => ['query' => $q[0], 'category' => null, 'count' => $q[1]], $flags['q'] ?? []);
        $miss = $flags['miss'] ?? null;
        $haggle = $lead?->isHaggle() ?? false;
        $retrievalMs = round(mt_rand(700, 1700) + mt_rand(0, 99) / 100, 1);

        return [
            'retrieved_chunks' => [],
            'embedding_ms' => round($retrievalMs * 0.9, 1),
            'retrieval_ms' => $retrievalMs,
            'search_ms' => $queries ? round(mt_rand(12, 60) + mt_rand(0, 99) / 100, 1) : 0,
            'generation_ms' => round($totalMs - $retrievalMs, 1),
            'total_ms' => (float) $totalMs,
            'input_tokens' => mt_rand(3800, 9500),
            'output_tokens' => max(20, (int) (mb_strlen((string) $question) / 2) + mt_rand(40, 180)),
            'failed' => false,
            'lead_captured' => $lead !== null && ! $haggle,
            'lead_ids' => $lead !== null && ! $haggle ? [$lead->id] : [],
            'price_approval_requested' => $haggle,
            'haggle_lead_ids' => $haggle ? [$lead->id] : [],
            'escalated' => false,
            'product_searches' => count($queries),
            'product_queries' => $queries,
            'unanswered' => $miss !== null,
            'unanswered_reason' => $miss,
            'unanswered_detected_by' => $miss === null ? null : ($miss === 'catalog_miss' ? 'retrieval' : 'reply_text'),
            'unanswered_question' => $miss === null ? null : mb_substr(trim($question), 0, 300),
            'short_follow_up' => false,
            'demo' => true,
            'demo_v2' => $isV2,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra
     */
    protected function message(Conversation $conversation, string $role, string $content, CarbonImmutable $at, ?array $meta = null, array $extra = []): Message
    {
        return Message::factory()->for($conversation)->create([
            'role' => $role,
            'content' => $content,
            'external_message_id' => null,
            'meta' => $meta,
            'source_type' => 'text',
            'voice_duration' => null,
            'created_at' => $at,
            'updated_at' => $at,
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $spec */
    protected function createLead(Agent $agent, ?Conversation $conversation, array $spec, string $source, CarbonImmutable $at): Lead
    {
        $haggle = ($spec['type'] ?? 'order') === 'haggle';
        $status = $spec['status'] === 'pending' ? HagglingService::STATUS_PENDING : $spec['status'];
        $seenAt = $at->addMinutes(mt_rand(3, 90));

        return Lead::factory()->for($agent)->create([
            'conversation_id' => $conversation?->id,
            'type' => $haggle ? HagglingService::TYPE : 'order',
            'customer_name' => $spec['name'],
            'phone' => $spec['phone'],
            'contact' => $spec['phone'],
            'items' => $spec['items'],
            'note' => $spec['note'] ?? null,
            'source' => $source,
            'status' => $status,
            'requested_price' => $spec['requested'] ?? null,
            'listed_price' => $spec['listed'] ?? null,
            'approved_price' => $spec['approved'] ?? null,
            'negotiation_summary' => $spec['summary'] ?? null,
            'details' => array_filter([
                'customer_name' => $spec['name'],
                'phone' => $spec['phone'],
                'items' => $spec['items'],
                'note' => $spec['note'] ?? null,
                'requested_price' => $spec['requested'] ?? null,
                'listed_price' => $spec['listed'] ?? null,
                'summary' => $spec['summary'] ?? null,
            ], fn ($v) => $v !== null),
            'seen_at' => ! empty($spec['unseen']) ? null : ($seenAt->isFuture() ? now() : $seenAt),
            'created_at' => $at,
            'updated_at' => $status === 'new' || $status === HagglingService::STATUS_PENDING ? $at : ($seenAt->isFuture() ? now() : $seenAt),
        ]);
    }

    /**
     * Ratings on ~30% of agent replies, ~70% of them positive.
     *
     * The thumbs-down are the ones written into the script — turns where the
     * agent had no exact match or fell back to "уточню у менеджера" — because
     * that is where real customers vote down. Thumbs-up come from the script
     * first and are topped up at random among the answers that were real.
     */
    protected function applyRatings(): void
    {
        $target = (int) round(count($this->replies) * self::RATED_SHARE);
        $rated = array_filter($this->replies, fn ($r) => $r['rating'] !== null);

        $pool = array_keys(array_filter($this->replies, fn ($r) => $r['rating'] === null && $r['eligible']));
        shuffle($pool);

        foreach (array_slice($pool, 0, max(0, $target - count($rated))) as $index) {
            $this->replies[$index]['rating'] = Message::RATING_UP;
        }

        foreach ($this->replies as $reply) {
            if ($reply['rating'] === null) {
                continue;
            }

            $ratedAt = CarbonImmutable::parse($reply['message']->created_at)->addSeconds(mt_rand(15, 420));

            $reply['message']->forceFill([
                'rating' => $reply['rating'],
                'rated_at' => $ratedAt->isFuture() ? now() : $ratedAt,
            ])->saveQuietly();
        }
    }

    /**
     * A moment in Khujand local time, stored as UTC. Today's chats never land
     * in the future when the seeder runs early in the morning.
     */
    protected function localTime(int $daysAgo, string $time): CarbonImmutable
    {
        $at = CarbonImmutable::now(self::TIMEZONE)->subDays($daysAgo)->setTimeFromTimeString($time)->utc();
        $latest = CarbonImmutable::now()->subMinutes(45);

        return $at->greaterThan($latest) ? $latest : $at;
    }

    protected function externalUserId(string $channel): string
    {
        return match ($channel) {
            'whatsapp', 'greenapi' => '99292'.mt_rand(1000000, 9999999),
            'instagram' => '178414'.mt_rand(10000000, 99999999).mt_rand(100, 999),
            default => (string) mt_rand(5000000000, 7999999999),
        };
    }

    protected function nextExternalId(string $prefix = 'demo'): string
    {
        return $prefix.'-'.(++$this->externalId);
    }

    /** @param  array<int, string>  $slugs */
    protected function summary(User $owner, array $slugs): void
    {
        if (! $this->command) {
            return;
        }

        $agents = Agent::whereIn('slug', $slugs)->withCount(['conversations', 'leads'])->get();
        $ids = $agents->pluck('id');
        $messages = Message::whereIn('conversation_id', Conversation::whereIn('agent_id', $ids)->select('id'));

        $rows = $agents->map(function (Agent $agent) {
            $msg = Message::whereIn('conversation_id', $agent->conversations()->select('id'));

            return [
                $agent->name,
                $agent->conversations_count,
                (clone $msg)->count(),
                $agent->leads_count,
                $agent->leads()->where('type', HagglingService::TYPE)->count(),
                (clone $msg)->where('rating', Message::RATING_UP)->count(),
                (clone $msg)->where('rating', Message::RATING_DOWN)->count(),
                $agent->datasetProducts()->count(),
            ];
        })->all();

        $pixelshop = Agent::where('slug', self::PIXELSHOP_SLUG)->first();
        $pixelshopMsg = null;

        if ($pixelshop) {
            $v2ConversationIds = Message::where('external_message_id', 'like', 'demo_v2-%')
                ->whereIn('conversation_id', $pixelshop->conversations()->select('id'))
                ->distinct()->pluck('conversation_id');

            $pixelshopMsg = Message::whereIn('conversation_id', $v2ConversationIds);

            $rows[] = [
                $pixelshop->name.' (demo_v2)',
                $v2ConversationIds->count(),
                (clone $pixelshopMsg)->count(),
                Lead::whereIn('conversation_id', $v2ConversationIds)->count(),
                Lead::whereIn('conversation_id', $v2ConversationIds)->where('type', HagglingService::TYPE)->count(),
                (clone $pixelshopMsg)->where('rating', Message::RATING_UP)->count(),
                (clone $pixelshopMsg)->where('rating', Message::RATING_DOWN)->count(),
                $pixelshop->datasetProducts()->count(),
            ];
        }

        $assistant = (clone $messages)->where('role', 'assistant')->count()
            + ($pixelshopMsg ? (clone $pixelshopMsg)->where('role', 'assistant')->count() : 0);
        $up = (clone $messages)->where('rating', Message::RATING_UP)->count()
            + ($pixelshopMsg ? (clone $pixelshopMsg)->where('rating', Message::RATING_UP)->count() : 0);
        $down = (clone $messages)->where('rating', Message::RATING_DOWN)->count()
            + ($pixelshopMsg ? (clone $pixelshopMsg)->where('rating', Message::RATING_DOWN)->count() : 0);

        $rows[] = ['ИТОГО', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2)), array_sum(array_column($rows, 3)),
            array_sum(array_column($rows, 4)), $up, $down, array_sum(array_column($rows, 7))];

        $this->command->newLine();
        $this->command->info("Demo data seeded for {$owner->email}");
        $this->command->table(['Agent', 'Conversations', 'Messages', 'Leads', 'Haggle', '👍', '👎', 'Products'], $rows);
        $this->command->line(sprintf(
            '  Agents: %d · Agent replies: %d · Rated: %d (%.0f%%) · 👍 %.0f%% / 👎 %.0f%%',
            $agents->count() + ($pixelshop ? 1 : 0), $assistant, $up + $down,
            $assistant ? ($up + $down) / $assistant * 100 : 0,
            ($up + $down) ? $up / ($up + $down) * 100 : 0,
            ($up + $down) ? $down / ($up + $down) * 100 : 0,
        ));
        $this->command->line('  Knowledge sources queued for embedding: '.count($this->sources));
    }
}
