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

            $choices = $request['ambiguous'];
        } elseif ($model === '' && $choices === [] && $this->catalog->enabledFor($conversation->agent)) {

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

    private function sendPhotoFor(ChannelDriverInterface $driver, Channel $channel, Conversation $conversation, Message $user, string $model, string $context, array $meta): array
    {
        try {

            $category = '';
            if ($this->catalog->enabledFor($conversation->agent)) {
                $found = $this->catalog->search($conversation->agent, ['query' => $model, 'limit' => 5]);
                $candidates = $this->ownedImageCandidates($conversation->agent, $found['products'] ?? [], $model);
                foreach ($found['products'] ?? [] as $product) {
                    $category .= ' '.($product['category'] ?? '');
                    if (! empty($product['image_url'])) {

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
