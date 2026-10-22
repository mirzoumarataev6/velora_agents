<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;

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

    protected function graphHost(): string
    {
        return $this->isInstagramLoginToken() ? 'graph.instagram.com' : 'graph.facebook.com';
    }
}
