<?php

namespace App\Services\Channels;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\SafeUrlGuard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class ImageReplyService
{
    public const MAX_IMAGES = 3;

    public function __construct(
        protected ImageRequestDetector $intent,
        protected WikimediaImageSearch $internet,
    ) {}

    public function sendIfRequested(
        ChannelDriverInterface $driver,
        Channel $channel,
        Agent $agent,
        string $externalUserId,
        string $customerText,
        array $assistantMeta,
        array $previousAssistantMeta = [],
        string $previousAssistantText = '',
        ?string $resolvedModel = null,
    ): int {
        if (! $this->intent->requested($customerText)
            || ! in_array($channel->type, ['telegram', 'whatsapp', 'greenapi'], true)) {
            return 0;
        }

        $requestedModel = $resolvedModel ?? $this->requestedModel($customerText, $previousAssistantText);
        if ($requestedModel === '') {
            // "Show a MacBook" after a discussion of several MacBooks does
            // not identify which product to show. Reusing the previous reply's
            // OMEN 16 (or searching for a random MacBook) misleads the buyer.
            return 0;
        }

        $images = [];
        $productCandidates = array_merge(
            $assistantMeta['product_image_candidates'] ?? [],
            $previousAssistantMeta['product_image_candidates'] ?? [],
        );
        foreach ($productCandidates as $candidate) {
            $url = $candidate['url'] ?? null;
            if (! is_string($url) || ! $this->isSafeRemoteImage($url)
                || ! $this->nameContainsModel((string) ($candidate['name'] ?? ''), $requestedModel)) {
                continue;
            }
            $images[] = ['url' => $url, 'caption' => Str::limit((string) ($candidate['name'] ?? ''), 900, '')];
            if (count($images) === self::MAX_IMAGES) {
                break;
            }
        }

        if ($images === []) {
            // Photo turns bypass the LLM/RAG, so uploads must be discoverable
            // without a previous retrieved chunk. Only this agent's files are
            // eligible, and their names must identify the selected model.
            foreach (KnowledgeSource::query()->where('agent_id', $agent->id)
                ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
                ->whereNotNull('file_path')->orderBy('id')->cursor() as $source) {
                $mimeType = $source->mime_type;
                if (! $this->nameContainsModel($source->name, $requestedModel)
                    // WhatsApp's image media endpoint accepts JPEG/PNG; passing
                    // WebP would turn a valid knowledge match into a provider error.
                    || ($channel->type === 'whatsapp' && $mimeType === 'image/webp')
                    || ! Storage::disk('local')->exists($source->file_path)) {
                    continue;
                }
                $extension = match ($mimeType) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $images[] = [
                    'url' => URL::temporarySignedRoute('knowledge.media.show', now()->addMinutes(8), [
                        'source' => $source,
                        'filename' => 'knowledge-'.$source->id.'.'.$extension,
                    ]),
                    'caption' => Str::limit($source->name, 900, ''),
                    'mime_type' => $mimeType,
                ];
                if (count($images) === self::MAX_IMAGES) {
                    break;
                }
            }
        }

        if ($images === [] && ($agent->behavior['internet_image_search'] ?? false)) {
            $productType = WikimediaImageSearch::detectProductType($customerText.' '.$previousAssistantText.' '.$requestedModel);
            // An internet outage must not hide an owner's matching upload.
            // Limit lexical search to one verified result for the chosen model.
            $images = $this->internet->search($requestedModel, 1, $productType);
        }

        foreach (array_slice($images, 0, self::MAX_IMAGES) as $image) {
            $driver->sendImage($channel, $externalUserId, $image['url'], $image['caption'] ?? '');
        }

        return min(count($images), self::MAX_IMAGES);
    }

    protected function isSafeRemoteImage(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        try {
            SafeUrlGuard::assertSafe($url);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Resolve only a model named in this turn, or an unambiguous pronoun follow-up. */
    public function requestedModel(string $customerText, string $previousAssistantText = ''): string
    {
        $current = $this->modelNamesFromContext($customerText);
        if ($current !== []) {
            return $current[0];
        }

        if (preg_match('/\b(?:его|её|ее|он|она|они|их|этот|эту|первый|второй|третий|those|that|it|first|second|third)\b/iu', $customerText) !== 1) {
            return '';
        }

        $previous = $this->modelNamesFromContext($previousAssistantText);

        return count($previous) === 1 ? $previous[0] : '';
    }

    /** @return string[] */
    protected function modelNamesFromContext(string $text): array
    {
        preg_match_all('/\b[A-Z][A-Za-z0-9-]*(?:\s+[A-Z0-9][A-Za-z0-9-]*){1,4}\b/u', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    protected function nameContainsModel(string $name, string $model): bool
    {
        // Catalogs often join the model and size (OMEN16); token boundaries
        // still prevent OMEN 16 from matching OMEN 160.
        $name = preg_replace('/(\p{L})(\d)/u', '$1 $2', $name);
        $model = preg_replace('/(\p{L})(\d)/u', '$1 $2', $model);
        $normalizedName = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name));
        $normalizedModel = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($model)));

        return $normalizedModel !== '' && str_contains(' '.$normalizedName.' ', ' '.$normalizedModel.' ');
    }
}
