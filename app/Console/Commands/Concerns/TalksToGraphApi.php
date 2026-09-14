<?php

namespace App\Console\Commands\Concerns;

use App\Models\Agent;
use App\Models\Channel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Graph API plumbing shared by the WhatsApp and Instagram diagnosis
 * commands.
 *
 * Extracted rather than copied: the two commands ask Meta different questions
 * (a WABA and a phone number on one side, an Instagram account and a Page on
 * the other) but they ask them the same WAY — same host, same token handling,
 * same raw-output discipline, same "is the tunnel the thing APP_URL claims"
 * check. A second copy of this would drift the first time either was fixed.
 *
 * Everything printed by dump() is raw. A diagnosis that paraphrases Meta's
 * error is a diagnosis you have to re-run by hand before you can trust it.
 */
trait TalksToGraphApi
{
    protected ?string $token = null;

    /**
     * The most recent channel of this type, optionally narrowed to one agent.
     */
    protected function resolveChannelOfType(string $type): ?Channel
    {
        $query = Channel::query()->where('type', $type)->with('agent');

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

    /** @param  array<string, mixed>  $query */
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
        return 'https://'.$this->graphHost().'/'.static::API_VERSION.'/'.ltrim($path, '/');
    }

    /**
     * Which Graph host to talk to. Overridden by the Instagram command, whose
     * newer IGAA tokens are only valid against graph.instagram.com.
     */
    protected function graphHost(): string
    {
        return 'graph.facebook.com';
    }

    /**
     * Raw, always. See the trait docblock.
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

    /**
     * APP_URL versus what ngrok is actually serving. Every webhook URL this app
     * hands to Meta is built from APP_URL, so a disagreement here means Meta is
     * posting into the void — the single most common cause of "it just stopped
     * working" after a laptop restart.
     */
    protected function reportEnvironment(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        $this->line('  APP_URL              '.($appUrl ?: '<fg=red>not set</>'));
        $this->line('  Live tunnel          '.($this->tunnelUrl() ?? '<fg=yellow>no local ngrok agent answering on :4040</>'));

        $tunnel = $this->tunnelUrl();

        if ($tunnel !== null && $appUrl !== '' && rtrim($tunnel, '/') !== $appUrl) {
            $this->components->warn('APP_URL and the live tunnel disagree — every webhook URL built from APP_URL is stale.');
        }
    }

    /**
     * The credential every Meta channel now needs and none of them used to
     * have: without an app secret the webhook signature cannot be verified, so
     * the driver refuses every delivery. Reported first because a channel that
     * looks perfect in every other respect will still receive nothing.
     */
    protected function reportSignatureReadiness(Channel $channel): void
    {
        $secret = $channel->credentials['app_secret'] ?? null;

        if (empty($secret)) {
            $this->line('  app_secret           <fg=red>missing — every incoming webhook is REJECTED</>');
            $this->components->warn(
                'This channel was connected before the app secret was required. Reconnect it in the '
                .'Channels tab and paste the App Secret from Meta App Dashboard → Settings → Basic.'
            );

            return;
        }

        $this->line('  app_secret           '.Channel::maskCredential($secret).' <fg=green>(signatures verifiable)</>');
    }

    /**
     * Token validity, straight from Meta rather than inferred from its shape.
     *
     * @return array<string, mixed> the `data` block, so callers can read scopes/app id
     */
    protected function reportTokenValidity(): array
    {
        $debug = $this->get('debug_token', [
            'input_token' => $this->token,
            'access_token' => $this->appToken() ?: $this->token,
        ]);
        $this->dump($debug);

        $data = $debug->json('data') ?? [];
        $expiresAt = $data['expires_at'] ?? null;

        if (($data['is_valid'] ?? null) === false) {
            $this->components->error('Meta says this token is NOT valid: '.($data['error']['message'] ?? 'no reason given'));
        }

        if ($expiresAt !== null) {
            $this->line((int) $expiresAt === 0
                ? '  <fg=green>Token does not expire.</>'
                : '  Expires at: '.date('Y-m-d H:i:s T', (int) $expiresAt)
                    .((int) $expiresAt < time() ? ' <fg=red>(EXPIRED)</>' : ' <fg=green>(still valid)</>'));
        }

        return $data;
    }
}
