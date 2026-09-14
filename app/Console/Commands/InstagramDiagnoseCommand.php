<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;

/**
 * Reports the REAL state of an Instagram Messaging setup, straight from the
 * Graph API — the sibling of whatsapp:diagnose, sharing its plumbing through
 * TalksToGraphApi rather than repeating it.
 *
 * Instagram has one failure mode WhatsApp does not, and it is the one that
 * wastes the most time: THE APP IS IN DEVELOPMENT MODE. In that state the
 * integration behaves perfectly for accounts listed as testers on the app and
 * is completely silent for everybody else — no error, no webhook, nothing to
 * find in a log. This command cannot see that setting (Meta exposes no API for
 * it), so it says so explicitly rather than reporting a clean bill of health
 * for a bot that answers nobody.
 *
 * The other Instagram-specific trap is the token host: the newer "Instagram API
 * with Instagram Login" flow issues IGAA-prefixed tokens that graph.facebook.com
 * rejects outright, while the older Page tokens (EAA) only work there. The
 * driver picks the host per token and so does this.
 */
#[Description('Check the Instagram Messaging setup against the Graph API')]
class InstagramDiagnoseCommand extends Command
{
    use Concerns\TalksToGraphApi;

    protected $signature = 'instagram:diagnose
        {--agent= : Agent id or slug whose stored Instagram channel to inspect}
        {--token= : Page/Instagram access token to use instead of the stored one}
        {--ig-id= : Instagram business account id to use instead of the stored one}
        {--app-id= : Meta App ID (needed for app-level subscription checks)}
        {--app-secret= : Meta App Secret (needed for app-level subscription checks)}';

    /** Matches InstagramDriver, deliberately — see that class. */
    protected const API_VERSION = 'v21.0';

    public function handle(): int
    {
        $this->components->info('Instagram Messaging diagnosis — '.self::API_VERSION);

        $channel = $this->resolveChannelOfType('instagram');

        $this->section('1. Local state');
        $expected = $this->reportLocalState($channel);

        $this->token = $this->option('token') ?: ($channel?->credentials['page_access_token'] ?? null);
        $igId = $this->option('ig-id') ?: ($channel?->credentials['ig_business_account_id'] ?? null);

        if (empty($this->token)) {
            $this->newLine();
            $this->components->error('No access token available — nothing to ask Meta.');
            $this->line('  Pass one with <options=bold>--token=…</>, or connect Instagram in the Channels tab first.');

            return self::FAILURE;
        }

        $this->line('  Graph host for this token: <options=bold>'.$this->graphHost().'</>'
            .($this->isInstagramLoginToken() ? ' (IGAA — Instagram Login flow)' : ' (EAA — Facebook Page flow)'));

        $this->section('2. Is the token valid?');
        $tokenAppId = $this->reportTokenIdentity();

        $this->section('3. The Instagram account');

        if (empty($igId)) {
            $this->components->warn('No ig_business_account_id stored or passed — skipping.');
        } else {
            $this->dump($this->get($igId, ['fields' => 'id,username,name,profile_picture_url']));

            $this->line('  Apps subscribed to this account (GET /{ig-id}/subscribed_apps):');
            $this->dump($this->get("{$igId}/subscribed_apps"));
        }

        $this->section('4. App-level webhook subscription (GET /{app-id}/subscriptions)');
        $appId = $this->option('app-id') ?: $tokenAppId;

        if (empty($appId) || empty($this->appToken())) {
            $this->components->warn(
                'Needs both --app-id and --app-secret (Meta only accepts an APP access token here).'
            );
        } else {
            // Always graph.facebook.com: app-level subscriptions live on the
            // app, not on the Instagram account, whichever host the messaging
            // token belongs to.
            $this->instagramLogin = false;
            $subscriptions = $this->get("{$appId}/subscriptions", ['access_token' => $this->appToken()], useToken: false);
            $this->dump($subscriptions);
            $this->compareSubscription($subscriptions, $expected);
        }

        $this->section('5. What this command cannot see');
        $this->line('  <fg=yellow>App Mode (Development vs Live)</> — Meta exposes no API for it.');
        $this->line('  In Development mode Instagram DMs work ONLY for accounts added as testers on the app,');
        $this->line('  and stay completely silent for everyone else. If everything above is green and real');
        $this->line('  customers still get no reply, this is almost always why.');
        $this->line('  Check it at: App Dashboard → top bar → App Mode toggle.');

        return self::SUCCESS;
    }

    /** @return string|null the expected callback URL, when there is a channel */
    protected function reportLocalState(?Channel $channel): ?string
    {
        $this->reportEnvironment();

        if ($channel === null) {
            $this->components->warn('No Instagram channel row exists for this account.');
            $this->line('  Nothing is stored: no account id, no page access token, no verify token.');

            return null;
        }

        $expected = route('webhooks.instagram', $channel);
        $stored = $channel->settings['webhook_url'] ?? null;
        $creds = $channel->credentials ?? [];

        $this->line('  Channel              id='.$channel->id.' uuid='.$channel->uuid.' status='.$channel->status);
        $this->line('  Agent                #'.$channel->agent_id.' '.($channel->agent?->name ?? ''));
        $this->line('  ig_business_account_id '.($creds['ig_business_account_id'] ?? '<fg=red>missing</>'));
        $this->line('  page_access_token    '.(empty($creds['page_access_token'])
            ? '<fg=red>missing</>'
            : Channel::maskCredential($creds['page_access_token']).' ('.strlen($creds['page_access_token']).' chars)'));
        $this->line('  verify_token         '.(empty($creds['verify_token'])
            ? '<fg=red>missing</>'
            : Channel::maskCredential($creds['verify_token'])));
        $this->reportSignatureReadiness($channel);
        $this->line('  Callback URL (now)   '.$expected);
        $this->line('  Callback URL (saved) '.($stored ?? '—'));

        if ($stored !== null && $stored !== $expected) {
            $this->components->warn('The saved callback URL is stale — Meta may still be posting to the old one.');
        }

        return $expected;
    }

    /**
     * Token identity, by whichever route the token's own flow allows.
     *
     * IGAA tokens cannot be introspected with /debug_token at all — the
     * endpoint lives on graph.facebook.com and rejects them — so for those the
     * honest check is "does it authenticate against /me on its own host".
     *
     * @return string|null the app id, when Meta will tell us
     */
    protected function reportTokenIdentity(): ?string
    {
        if ($this->isInstagramLoginToken()) {
            $this->line('  IGAA tokens cannot be introspected via /debug_token; asking /me instead.');
            $me = $this->get('me', ['fields' => 'id,username']);
            $this->dump($me);

            if ($me->failed()) {
                $this->components->error('The token did not authenticate. It is expired, revoked, or for another account.');
            }

            return null;
        }

        return $this->reportTokenValidity()['app_id'] ?? null;
    }

    /**
     * Is the callback URL right, and is the `messages` field actually subscribed
     * for the `instagram` object? A subscription pointing at the correct URL but
     * carrying only `comments` delivers no DMs at all.
     */
    protected function compareSubscription(Response $subscriptions, ?string $expected): void
    {
        $instagram = collect($subscriptions->json('data') ?? [])
            ->firstWhere('object', 'instagram');

        if ($instagram === null) {
            $this->components->warn('No subscription for the "instagram" object on this app — DMs will never arrive.');

            return;
        }

        $callback = $instagram['callback_url'] ?? null;

        if ($expected !== null && $callback !== $expected) {
            $this->components->warn('Callback URL mismatch.');
            $this->line('    Meta posts to: '.($callback ?? '—'));
            $this->line('    This app expects: '.$expected);
        } elseif ($expected !== null) {
            $this->line('  <fg=green>Callback URL matches this app.</>');
        }

        $fields = collect($instagram['fields'] ?? [])->pluck('name');

        $this->line('  Subscribed fields: '.($fields->isEmpty() ? '<fg=red>none</>' : $fields->implode(', ')));

        if (! $fields->contains('messages')) {
            $this->components->warn('The "messages" field is NOT subscribed — comments may arrive, DMs will not.');
        }
    }

    protected bool $instagramLogin = true;

    protected function isInstagramLoginToken(): bool
    {
        return $this->instagramLogin && str_starts_with((string) $this->token, 'IGAA');
    }

    /** IGAA tokens are only valid against graph.instagram.com; see InstagramDriver. */
    protected function graphHost(): string
    {
        return $this->isInstagramLoginToken() ? 'graph.instagram.com' : 'graph.facebook.com';
    }
}
