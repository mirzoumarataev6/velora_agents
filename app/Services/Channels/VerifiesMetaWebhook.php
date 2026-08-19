<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared GET-verification handshake for Meta platforms (WhatsApp Cloud API,
 * Instagram Messaging) — both use the identical hub.mode/hub.verify_token/
 * hub.challenge flow described at https://developers.facebook.com/docs/graph-api/webhooks/getting-started
 *
 * Important gotcha: PHP's query-string parser converts dots in parameter
 * names to underscores when populating $_GET (and therefore Laravel's
 * Request::query()) — Meta's documented "hub.mode" arrives here as
 * "hub_mode", NOT "hub.mode". This is a common source of silently-broken
 * Meta webhook integrations; handled explicitly below rather than trusting
 * the dotted names shown in Meta's docs.
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
}
