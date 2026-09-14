<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook authentication for Meta platforms (WhatsApp Cloud API, Instagram
 * Messaging) — the GET verification handshake, and the HMAC signature on every
 * POST.
 *
 * Both platforms behave identically here, which is why this is one trait: the
 * hub.mode/hub.verify_token/hub.challenge flow and the X-Hub-Signature-256
 * header are Graph-wide, not product-specific. Instagram needs the signature
 * check every bit as much as WhatsApp does — its webhook is an ordinary public
 * HTTPS endpoint carrying customer messages, and the only thing Meta gives
 * either of them to prove a delivery is genuine is this header.
 *
 * Important gotcha on the handshake: PHP's query-string parser converts dots in
 * parameter names to underscores when populating $_GET (and therefore Laravel's
 * Request::query()) — Meta's documented "hub.mode" arrives here as "hub_mode",
 * NOT "hub.mode". This is a common source of silently-broken Meta webhook
 * integrations; handled explicitly below rather than trusting the dotted names
 * shown in Meta's docs.
 */
trait VerifiesMetaWebhook
{
    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = $channel->credentials['verify_token'] ?? null;

        if ($mode !== 'subscribe' || empty($expected) || ! hash_equals((string) $expected, (string) $token)) {
            return response('Forbidden', 403);
        }

        // Meta requires the raw challenge value echoed back as the response body.
        return response((string) $challenge, 200);
    }

    /**
     * Verify Meta's HMAC over the POST body.
     *
     * Meta signs every webhook delivery with the App Secret and sends
     * `X-Hub-Signature-256: sha256=<hex>`. Until this existed, both Meta
     * drivers returned `true` unconditionally and the only thing standing
     * between the internet and "inject any customer message into any
     * conversation" was the channel UUID in the URL — which travels in server
     * logs, proxy logs and the Meta dashboard, and is a URL, not a secret.
     *
     * FAILS CLOSED when no app secret is stored. A channel connected before
     * this field was collected cannot have its deliveries verified, and
     * accepting them unverified is exactly the hole this closes; the owner
     * reconnects the channel to supply the secret. The log line says so
     * explicitly, because silence here looks identical to "Meta stopped
     * sending".
     */
    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        $appSecret = $channel->credentials['app_secret'] ?? null;

        if (empty($appSecret)) {
            Log::warning('Meta webhook rejected: this channel has no app secret stored, so its deliveries cannot be verified. Reconnect the channel to supply one.', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($header, 'sha256=')) {
            Log::warning('Meta webhook rejected: missing or malformed X-Hub-Signature-256 header.', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            return false;
        }

        // The RAW body, not a re-encoded array. Meta signs the exact bytes it
        // sent, and json_encode() of the decoded payload would reorder keys,
        // change escaping and re-format floats — producing a different digest
        // for a delivery that was perfectly genuine.
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $appSecret);

        if (! hash_equals($expected, $header)) {
            Log::warning('Meta webhook rejected: signature did not match the stored app secret.', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            return false;
        }

        return true;
    }
}
