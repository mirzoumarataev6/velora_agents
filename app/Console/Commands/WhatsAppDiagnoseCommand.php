<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Channel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reports the REAL state of a WhatsApp Cloud API setup, straight from the Graph
 * API, and can repair the webhook subscription without touching a browser.
 *
 * Written because the interesting failures here are all invisible from inside
 * the app: a token that expired last Tuesday, a callback URL still pointing at
 * yesterday's tunnel, an app subscribed to the wrong fields, a WABA that never
 * got subscribed to the app at all. Every one of those looks identical from
 * this side — messages simply never arrive — so this command asks Meta rather
 * than inferring.
 *
 * Everything printed is the raw response. Nothing here summarises an error into
 * a guess about what it means.
 */
#[Description('Check (and optionally repair) the WhatsApp Cloud API setup against the Graph API')]
class WhatsAppDiagnoseCommand extends Command
{
    protected $signature = 'whatsapp:diagnose
        {--agent= : Agent id or slug whose stored WhatsApp channel to inspect}
        {--token= : Access token to use instead of the stored one}
        {--phone-id= : Phone number ID to use instead of the stored one}
        {--app-id= : Meta App ID (needed for app-level subscription checks)}
        {--app-secret= : Meta App Secret (needed for app-level subscription checks)}
        {--waba= : WhatsApp Business Account ID, if you already know it}
        {--fix : Repair the webhook subscription to point at this app\'s callback URL}';

    /**
     * Matches the driver, deliberately. A diagnosis run against a different API
     * version than the one that actually sends messages can come back clean
     * while the real path is broken.
     */
    protected const API_VERSION = 'v21.0';

    protected ?string $token = null;

    public function handle(): int
    {
        $this->components->info('WhatsApp Cloud API diagnosis — '.self::API_VERSION);

        $channel = $this->resolveChannel();

        $this->section('1. Local state');
        $local = $this->reportLocalState($channel);

        $this->token = $this->option('token') ?: ($channel?->credentials['access_token'] ?? null);
        $phoneId = $this->option('phone-id') ?: ($channel?->credentials['phone_number_id'] ?? null);

        if (empty($this->token)) {
            $this->newLine();
            $this->components->error('No access token available — nothing to ask Meta.');
            $this->line('  Pass one with <options=bold>--token=EAA…</>, or connect WhatsApp in the Channels tab first.');
            $this->line('  Everything below this point needs a token; the local report above is complete.');

            return self::FAILURE;
        }

        $this->section('2. Is the token valid? (GET /debug_token)');
        $debug = $this->get('debug_token', [
            'input_token' => $this->token,
            'access_token' => $this->appToken() ?: $this->token,
        ]);
        $this->dump($debug);

        $scopes = $debug->json('data.granular_scopes') ?? [];
        $tokenAppId = $debug->json('data.app_id');
        $expiresAt = $debug->json('data.expires_at');

        if ($expiresAt !== null) {
            $this->line($expiresAt === 0
                ? '  <fg=green>Token does not expire.</>'
                : '  Expires at: '.date('Y-m-d H:i:s T', (int) $expiresAt)
                    .($expiresAt < time() ? ' <fg=red>(EXPIRED)</>' : ' <fg=green>(still valid)</>'));
        }

        $this->section('3. The phone number (GET /{phone-number-id})');

        if (empty($phoneId)) {
            $this->components->warn('No phone_number_id stored or passed — skipping.');
        } else {
            $this->dump($this->get($phoneId, [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type,'
                    .'code_verification_status,throughput,status',
            ]));
        }

        $this->section('4. WhatsApp Business Account(s) this token can see');
        $wabaIds = $this->discoverWabaIds($scopes);

        if ($wabaIds === []) {
            $this->components->warn(
                'No WABA id found. The token may lack whatsapp_business_management, or it is a temporary '
                .'token whose granular_scopes carry no target_ids.'
            );
        }

        foreach ($wabaIds as $wabaId) {
            $this->line("  WABA <options=bold>{$wabaId}</>");
            $this->dump($this->get($wabaId, ['fields' => 'id,name,timezone_id,message_template_namespace']));

            $this->line('  Apps subscribed to this WABA (GET /{waba-id}/subscribed_apps):');
            $this->dump($this->get("{$wabaId}/subscribed_apps"));
        }

        $this->section('5. App-level webhook subscription (GET /{app-id}/subscriptions)');
        $appId = $this->option('app-id') ?: $tokenAppId;

        if (empty($appId) || empty($this->appToken())) {
            $this->components->warn(
                'Needs both --app-id and --app-secret (Meta only accepts an APP access token here, '
                .'never a user or system-user token).'
            );
            $this->line('  App id '.(empty($appId) ? '<fg=red>unknown</>' : "<options=bold>{$appId}</> (from the token)"));
            $this->line('  App secret '.(empty($this->option('app-secret')) ? '<fg=red>not supplied</>' : '<fg=green>supplied</>'));
        } else {
            $subscriptions = $this->get("{$appId}/subscriptions", ['access_token' => $this->appToken()], useToken: false);
            $this->dump($subscriptions);
            $this->compareCallbackUrl($subscriptions, $local['expected_webhook_url']);
        }

        if ($this->option('fix')) {
            $this->section('6. Repairing the subscription');
            $this->repair($appId, $wabaIds, $local, $channel);
        } elseif ($local['expected_webhook_url'] !== null) {
            $this->newLine();
            $this->line('  Re-run with <options=bold>--fix --app-id=… --app-secret=…</> to point the callback URL here.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{expected_webhook_url: ?string, stored_webhook_url: ?string}
     */
    protected function reportLocalState(?Channel $channel): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $this->line('  APP_URL              '.($appUrl ?: '<fg=red>not set</>'));
        $this->line('  Live tunnel          '.($this->tunnelUrl() ?? '<fg=yellow>no local ngrok agent answering on :4040</>'));

        $tunnel = $this->tunnelUrl();

        if ($tunnel !== null && $appUrl !== '' && rtrim($tunnel, '/') !== $appUrl) {
            $this->components->warn('APP_URL and the live tunnel disagree — every webhook URL built from APP_URL is stale.');
        }

        if ($channel === null) {
            $this->components->warn('No WhatsApp channel row exists for this account.');
            $this->line('  Nothing is stored: no phone_number_id, no access token, no verify token.');

            return ['expected_webhook_url' => null, 'stored_webhook_url' => null];
        }

        $expected = route('webhooks.whatsapp', $channel);
        $stored = $channel->settings['webhook_url'] ?? null;
        $creds = $channel->credentials ?? [];

        $this->line('  Channel              id='.$channel->id.' uuid='.$channel->uuid.' status='.$channel->status);
        $this->line('  Agent                #'.$channel->agent_id.' '.($channel->agent?->name ?? ''));
        $this->line('  phone_number_id      '.($creds['phone_number_id'] ?? '<fg=red>missing</>'));
        $this->line('  access_token         '.(empty($creds['access_token'])
            ? '<fg=red>missing</>'
            : Channel::maskCredential($creds['access_token']).' ('.strlen($creds['access_token']).' chars)'));
        $this->line('  verify_token         '.(empty($creds['verify_token'])
            ? '<fg=red>missing</>'
            : Channel::maskCredential($creds['verify_token'])));
        $this->line('  Callback URL (now)   '.$expected);
        $this->line('  Callback URL (saved) '.($stored ?? '—'));

        if ($stored !== null && $stored !== $expected) {
            $this->components->warn('The saved callback URL is stale — Meta may still be posting to the old one.');
        }

        return ['expected_webhook_url' => $expected, 'stored_webhook_url' => $stored];
    }

    /**
     * The WABA ids the token actually carries authority over.
     *
     * debug_token's granular_scopes is the only place that reports this without
     * already knowing a business id, which is why it is read here rather than
     * walking /me/businesses — a system-user token frequently cannot.
     *
     * @param  array<int, array<string, mixed>>  $scopes
     * @return array<int, string>
     */
    protected function discoverWabaIds(array $scopes): array
    {
        if ($explicit = $this->option('waba')) {
            return [$explicit];
        }

        $ids = [];

        foreach ($scopes as $scope) {
            if (in_array($scope['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                foreach ($scope['target_ids'] ?? [] as $id) {
                    $ids[] = (string) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    protected function compareCallbackUrl(Response $subscriptions, ?string $expected): void
    {
        if ($expected === null) {
            return;
        }

        foreach ($subscriptions->json('data') ?? [] as $entry) {
            if (($entry['object'] ?? null) !== 'whatsapp_business_account') {
                continue;
            }

            $callback = $entry['callback_url'] ?? null;
            $fields = array_column($entry['fields'] ?? [], 'name');

            $this->newLine();
            $this->line('  Subscribed callback  '.($callback ?? '—'));
            $this->line('  Fields               '.($fields === [] ? '—' : implode(', ', $fields)));

            if ($callback !== $expected) {
                $this->components->warn('STALE: Meta is posting to a URL that is not this app\'s webhook.');
            } else {
                $this->components->info('Callback URL matches this app.');
            }

            if (! in_array('messages', $fields, true)) {
                $this->components->warn('The "messages" field is NOT subscribed — inbound messages will never arrive.');
            }

            return;
        }

        $this->components->warn('No whatsapp_business_account subscription on this app at all.');
    }

    /**
     * Point the app's subscription at this deployment, and make sure the WABA
     * is actually subscribed to the app.
     *
     * Both halves are required and they are separate objects in Meta's model:
     * the first says "send WhatsApp events to this URL", the second says "this
     * business account is one you may send events about". Setting only the
     * first is the single most common reason a correct-looking setup delivers
     * nothing.
     *
     * @param  array<int, string>  $wabaIds
     * @param  array{expected_webhook_url: ?string, stored_webhook_url: ?string}  $local
     */
    protected function repair(?string $appId, array $wabaIds, array $local, ?Channel $channel): void
    {
        $callback = $local['expected_webhook_url'];
        $verifyToken = $channel?->credentials['verify_token'] ?? null;

        if ($callback === null || empty($verifyToken)) {
            $this->components->error('Cannot repair: no connected WhatsApp channel, so there is no callback URL or verify token to register.');

            return;
        }

        if (empty($appId) || empty($this->appToken())) {
            $this->components->error('Cannot repair: --app-id and --app-secret are both required (Meta requires an app access token for this call).');

            return;
        }

        $this->line('  POST /'.$appId.'/subscriptions');
        $this->dump(Http::asForm()->timeout(20)->post($this->url("{$appId}/subscriptions"), [
            'object' => 'whatsapp_business_account',
            'callback_url' => $callback,
            'verify_token' => $verifyToken,
            'fields' => 'messages',
            'access_token' => $this->appToken(),
        ]));

        // Meta calls the URL above synchronously during this POST, so a failure
        // here is usually the handshake failing, not the subscription itself.
        $this->line('  (Meta calls the callback URL during that POST — a verification failure shows up as the error above.)');

        foreach ($wabaIds as $wabaId) {
            $this->line("  POST /{$wabaId}/subscribed_apps");
            $this->dump(Http::withToken($this->token)->timeout(20)->post($this->url("{$wabaId}/subscribed_apps")));
        }

        if ($channel !== null) {
            $channel->settings = array_merge($channel->settings ?? [], ['webhook_url' => $callback]);
            $channel->save();
            $this->components->info('Stored callback URL refreshed locally.');
        }
    }

    protected function resolveChannel(): ?Channel
    {
        $query = Channel::query()->where('type', 'whatsapp')->with('agent');

        if ($agentRef = $this->option('agent')) {
            $agent = Agent::query()
                ->when(is_numeric($agentRef), fn ($q) => $q->where('id', $agentRef), fn ($q) => $q->where('slug', $agentRef))
                ->first();

            if ($agent === null) {
                $this->components->warn("No agent matched \"{$agentRef}\".");

                return null;
            }

            $query->where('agent_id', $agent->id);
        }

        return $query->latest('id')->first();
    }

    /** Meta accepts only `{app-id}|{app-secret}` on app-level subscription calls. */
    protected function appToken(): ?string
    {
        $id = $this->option('app-id');
        $secret = $this->option('app-secret');

        return $id && $secret ? "{$id}|{$secret}" : null;
    }

    /** @param array<string, mixed> $query */
    protected function get(string $path, array $query = [], bool $useToken = true): Response
    {
        $request = Http::timeout(20)->acceptJson();

        if ($useToken) {
            $request = $request->withToken($this->token);
        }

        try {
            return $request->get($this->url($path), $query);
        } catch (Throwable $e) {
            $this->components->error("Request to /{$path} failed: ".$e->getMessage());

            return new Response(new \GuzzleHttp\Psr7\Response(0, [], '{}'));
        }
    }

    protected function url(string $path): string
    {
        return 'https://graph.facebook.com/'.self::API_VERSION.'/'.ltrim($path, '/');
    }

    /**
     * Raw, always. A diagnosis that paraphrases Meta's error is a diagnosis you
     * have to re-run by hand to trust.
     */
    protected function dump(Response $response): void
    {
        $status = $response->status();
        $colour = $response->successful() ? 'green' : 'red';

        $this->line("  <fg={$colour}>HTTP {$status}</>");

        $body = json_decode($response->body(), true);

        $this->line(collect(explode("\n", $body === null
            ? trim($response->body())
            : json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
            ->map(fn ($line) => '    '.$line)
            ->implode("\n"));

        $this->newLine();
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>── {$title}</>");
    }

    /** The URL ngrok is actually serving right now, straight from its local agent API. */
    protected function tunnelUrl(): ?string
    {
        static $cached = false;
        static $value = null;

        if ($cached) {
            return $value;
        }

        $cached = true;

        try {
            $response = Http::timeout(3)->get('http://127.0.0.1:4040/api/tunnels');
            $value = $response->successful() ? ($response->json('tunnels.0.public_url') ?: null) : null;
        } catch (Throwable) {
            $value = null;
        }

        return $value;
    }
}
