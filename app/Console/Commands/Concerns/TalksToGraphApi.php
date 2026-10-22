<?php

namespace App\Console\Commands\Concerns;

use App\Models\Agent;
use App\Models\Channel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

trait TalksToGraphApi
{
    protected ?string $token = null;

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

    protected function appToken(): ?string
    {
        $id = $this->option('app-id');
        $secret = $this->option('app-secret');

        return $id && $secret ? "{$id}|{$secret}" : null;
    }

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

    protected function graphHost(): string
    {
        return 'graph.facebook.com';
    }

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
