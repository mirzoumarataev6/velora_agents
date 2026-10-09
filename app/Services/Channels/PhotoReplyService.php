<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Dataset\ProductSearchService;
use App\Services\Reply\CustomerLocale;
use Illuminate\Support\Facades\Log;
use Throwable;

class PhotoReplyService
{
    public function __construct(
        private ImageReplyService $images,
        private ProductSearchService $catalog,
        private CustomerLocale $locales,
    ) {}

    public function reply(ChannelDriverInterface $driver, Channel $channel, Conversation $conversation, Message $user, array $request): Message
    {
        $model = $request['model'];
        $status = $model === '' ? 'clarify' : 'missing';
        $meta = $request['evidence'];
        $sent = 0;
        if ($model !== '') {
            try {
                // A photo follow-up should not require another LLM turn to
                // repeat the price list. Query the existing safe catalog API
                // once, keeping only photo evidence for the selected model.
                $category = '';
                if ($this->catalog->enabledFor($conversation->agent)) {
                    $found = $this->catalog->search($conversation->agent, ['query' => $model, 'limit' => 5]);
                    $candidates = [];
                    foreach ($found['products'] ?? [] as $product) {
                        $category .= ' '.($product['category'] ?? '');
                        if (! empty($product['image_url'])) {
                            $candidates[] = ['name' => $product['name'], 'url' => $product['image_url']];
                        }
                    }
                    $meta['product_image_candidates'] = array_merge($candidates, $meta['product_image_candidates'] ?? []);
                }
                $sent = $this->images->sendIfRequested(
                    $driver, $channel, $conversation->agent, $conversation->external_user_id,
                    'photo '.$model, $meta, [], $user->content.' '.$request['context'].' '.$category, $model,
                );
                $status = $sent > 0 ? 'sent' : 'missing';
            } catch (Throwable $e) {
                $status = 'failed';
                Log::warning('Failed to deliver requested image reply', [
                    'channel_id' => $channel->id, 'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $locale = $request['locale'] ?? $this->locales->forCustomer($conversation);
        $text = __('replies.photo.'.$status, ['model' => $model], $locale);
        if ($status === 'clarify' && $request['choices'] !== []) {
            $options = array_map(fn ($item, $index) => ($index + 1).'. '.$item['name'], $request['choices'], array_keys($request['choices']));
            $text = __('replies.photo.choose', [], $locale)."\n".implode("\n", $options);
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant', 'content' => $text,
            'meta' => array_merge($meta, [
                'photo_status' => $status, 'photo_model' => $model, 'photo_count' => $sent,
                'photo_choices' => $request['choices'], 'photo_context' => $request['context'],
                'photo_locale' => $locale,
                'failed' => false, 'input_tokens' => 0, 'output_tokens' => 0,
            ]),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }
}
