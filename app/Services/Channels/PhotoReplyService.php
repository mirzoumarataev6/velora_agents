<?php

namespace App\Services\Channels;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\DatasetProduct;
use App\Models\Message;
use App\Services\Dataset\ProductSearchService;
use App\Services\Reply\CustomerLocale;
use Illuminate\Support\Facades\Log;
use Throwable;

class PhotoReplyService
{
    /** Filler words stripped before a fresh (no pending menu) message is tried against the catalog. */
    private const QUERY_STOPWORDS = '/\b(?:как|выгляд\w*|покаж\w*|показ\w*|фото\w*|картинк\w*|изображен\w*'
        .'|это|они|он|она|что|какой|какая|какие|пожалуйста|а|и|но|ну|да|то|же|ли'
        .'|please|show|photo|picture|image|of|the|how|does|look\w*|laptop|notebook|and|but|so)\b/iu';

    public function __construct(
        private ImageReplyService $images,
        private ProductSearchService $catalog,
        private CustomerLocale $locales,
    ) {}

    public function reply(ChannelDriverInterface $driver, Channel $channel, Conversation $conversation, Message $user, array $request): Message
    {
        $locale = $request['locale'] ?? $this->locales->forCustomer($conversation);

        if ($request['all'] ?? false) {
            return $this->replyAll($driver, $channel, $conversation, $user, $request, $locale);
        }

        $model = $request['model'];
        $choices = $request['choices'];

        if ($model === '' && ($request['ambiguous'] ?? []) !== []) {
            // "Нео" matched two configurations: ask again, but only among the
            // ones it actually matched, not the whole original menu.
            $choices = $request['ambiguous'];
        } elseif ($model === '' && $choices === [] && $this->catalog->enabledFor($conversation->agent)) {
            // No pending menu to resolve against: a fresh "как выглядит
            // виктус" names a model directly often enough that forcing a
            // clarify step here would be the WRONG behaviour this flow exists
            // to avoid. Ask the catalog itself, which already resolves a
            // Cyrillic model word against a Latin catalog name — see
            // SearchSkeleton. Only acted on when it is unambiguous.
            $model = $this->resolveModelFromCatalog($conversation->agent, $user->content);
        }

        [$status, $sent, $meta] = $model !== ''
            ? $this->sendPhotoFor($driver, $channel, $conversation, $user, $model, $request['context'], $request['evidence'])
            : ['clarify', 0, $request['evidence']];

        $text = __('replies.photo.'.$status, ['model' => $model], $locale);
        if ($status === 'clarify' && $choices !== []) {
            $options = array_map(fn ($item, $index) => ($index + 1).'. '.$item['name'].($item['price'] !== '' ? ' — '.$item['price'] : ''), $choices, array_keys($choices));
            $text = __('replies.photo.choose', [], $locale)."\n".implode("\n", $options);
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant', 'content' => $text,
            'meta' => array_merge($meta, [
                'photo_status' => $status, 'photo_model' => $model, 'photo_count' => $sent,
                'photo_choices' => $choices, 'photo_context' => $request['context'],
                'photo_locale' => $locale,
                'failed' => false, 'input_tokens' => 0, 'output_tokens' => 0,
            ]),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * "Все"/"всё": send a photo for every offered choice instead of making
     * the customer pick one, capped at ImageReplyService::MAX_IMAGES so a
     * long menu does not turn into a dozen images. Each photo's own caption
     * (built in ImageReplyService/WikimediaImageSearch) already carries the
     * product name and — for an internet result — its licence; the
     * surrounding text is the honest summary of what actually went out.
     */
    private function replyAll(ChannelDriverInterface $driver, Channel $channel, Conversation $conversation, Message $user, array $request, string $locale): Message
    {
        $choices = array_slice($request['choices'], 0, ImageReplyService::MAX_IMAGES);
        $totalSent = 0;
        $meta = $request['evidence'];

        foreach ($choices as $choice) {
            [, $sent, $choiceMeta] = $this->sendPhotoFor($driver, $channel, $conversation, $user, $choice['name'], $request['context'], $meta);
            $totalSent += $sent;
            $meta = $choiceMeta;
        }

        $status = match (true) {
            $totalSent === 0 => 'none_found',
            $totalSent >= count($choices) => 'sent_all',
            default => 'sent_some',
        };

        $message = $conversation->messages()->create([
            'role' => 'assistant', 'content' => __('replies.photo.'.$status, [], $locale),
            'meta' => array_merge($meta, [
                'photo_status' => $status, 'photo_model' => '', 'photo_count' => $totalSent,
                'photo_choices' => $request['choices'], 'photo_context' => $request['context'],
                'photo_locale' => $locale,
                'failed' => false, 'input_tokens' => 0, 'output_tokens' => 0,
            ]),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * Resolve, send and report on one model. Shared by the single-model path
     * and the "send all" loop so both go through exactly the same catalog
     * lookup and delivery attempt.
     *
     * @return array{0: string, 1: int, 2: array} [status, images sent, updated evidence meta]
     */
    private function sendPhotoFor(ChannelDriverInterface $driver, Channel $channel, Conversation $conversation, Message $user, string $model, string $context, array $meta): array
    {
        try {
            // A photo follow-up should not require another LLM turn to
            // repeat the price list. Query the existing safe catalog API
            // once, keeping only photo evidence for the selected model.
            $category = '';
            if ($this->catalog->enabledFor($conversation->agent)) {
                $found = $this->catalog->search($conversation->agent, ['query' => $model, 'limit' => 5]);
                $candidates = $this->ownedImageCandidates($conversation->agent, $found['products'] ?? [], $model);
                foreach ($found['products'] ?? [] as $product) {
                    $category .= ' '.($product['category'] ?? '');
                    if (! empty($product['image_url'])) {
                        // Carries the price into the catalog photo's caption
                        // (ImageReplyService) so a "send all" burst reads as
                        // "Name — price" per image, not a bare picture.
                        $candidates[] = ['name' => $product['name'], 'url' => $product['image_url'], 'price' => $product['price'] ?? null];
                    }
                }
                $meta['product_image_candidates'] = array_merge($candidates, $meta['product_image_candidates'] ?? []);
            }
            $sent = $this->images->sendIfRequested(
                $driver, $channel, $conversation->agent, $conversation->external_user_id,
                'photo '.$model, $meta, [], $user->content.' '.$context.' '.$category, $model,
            );

            return [$sent > 0 ? 'sent' : 'missing', $sent, $meta];
        } catch (Throwable $e) {
            Log::warning('Failed to deliver requested image reply', [
                'channel_id' => $channel->id, 'conversation_id' => $conversation->id,
                'model' => $model, 'error' => $e->getMessage(),
            ]);

            return ['failed', 0, $meta];
        }
    }

    /**
     * The owner's own photos for whichever catalog products the search
     * above already resolved — the trusted source ImageReplyService sends
     * first and without the fuzzy name/relevance check every other candidate
     * still goes through, since these are tied to a product the catalog
     * query, not a loose text match, already decided is the right one.
     * Correlated by name (the only identifier search_products exposes) and
     * only for the uploaded catalog — a live database connection has no
     * local product row to hang a photo on.
     *
     * Stamped with `for_model` — the exact model this call resolved, not a
     * substring guess — so ImageReplyService can still tell these apart once
     * they are in play: replyAll()'s "send all" loop re-sends sendPhotoFor()
     * per choice while *accumulating* `product_image_candidates` across
     * calls (each call's evidence carries the previous ones forward), and
     * without this tag an earlier choice's owned photo — never relevance-
     * checked — would still be sitting in that accumulated evidence and get
     * sent again on a later choice's turn.
     */
    private function ownedImageCandidates(Agent $agent, array $matchedProducts, string $model): array
    {
        if ($agent->usesLiveDatabase() || $matchedProducts === []) {
            return [];
        }

        $names = array_unique(array_column($matchedProducts, 'name'));
        $owned = DatasetProduct::where('agent_id', $agent->id)->whereIn('name', $names)->with('images')->get();

        $candidates = [];
        foreach ($owned as $product) {
            foreach ($product->images as $image) {
                $candidates[] = ['name' => $product->name, 'url' => $image->url(), 'owned' => true, 'for_model' => $model];
            }
        }

        return $candidates;
    }

    /** A fresh message naming exactly one real product, with no pending menu to resolve against. */
    private function resolveModelFromCatalog(Agent $agent, string $text): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::QUERY_STOPWORDS, ' ', $text)));

        if (mb_strlen($query) < 2) {
            return '';
        }

        $found = $this->catalog->search($agent, ['query' => $query, 'limit' => 5]);
        $names = array_unique(array_column($found['products'] ?? [], 'name'));

        return count($names) === 1 ? $names[0] : '';
    }
}
