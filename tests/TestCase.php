<?php

namespace Tests;

use Database\Factories\ChannelFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstRunningOnARealDatabase();

        // Human-paced reply delivery pauses between messages. No test should
        // spend real wall-clock time on that — record the pauses instead, so
        // they can be asserted on without slowing the suite down.
        Sleep::fake();
    }

    /**
     * Refuse to run against anything but throwaway SQLite.
     *
     * RefreshDatabase opens by running `migrate:fresh`, which DROPS EVERY
     * TABLE. phpunit.xml pins the connection to sqlite/:memory: through the
     * environment — but a cached config (bootstrap/cache/config.php, written by
     * `php artisan optimize`) is read in preference to the environment, so
     * those overrides are silently ignored and the drop lands on the real MySQL
     * database named in .env. That is not hypothetical: it destroyed the local
     * `agent` database on 2026-08-21, and there were no binlogs or dumps to
     * recover from.
     *
     * `composer run test` clears the config cache first and is the safe way in,
     * but nothing stopped a bare `php artisan test` from bypassing it. This
     * does: it asserts the actual resolved connection rather than trusting any
     * layer of configuration, and fails loudly instead of dropping anything.
     */
    protected function guardAgainstRunningOnARealDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite') {
            $this->fail(
                "Refusing to run tests: the database connection is [{$connection}] using driver [{$driver}], not sqlite. "
                ."RefreshDatabase would run migrate:fresh and DROP EVERY TABLE in [{$database}].\n"
                ."This almost always means a cached config is overriding phpunit.xml. Fix it with:\n"
                ."    php artisan config:clear\n"
                .'or run the suite the safe way: composer run test'
            );
        }

        // A file-backed SQLite database is still somebody's data. Only the
        // in-memory one is genuinely disposable.
        if ($database !== ':memory:') {
            $this->fail(
                "Refusing to run tests: sqlite is pointed at the file [{$database}] rather than :memory:. "
                .'RefreshDatabase would drop every table in it.'
            );
        }

        // Belt and braces: ask the live PDO handle what it actually connected
        // to, in case something rebinds the connection after config is read.
        $actual = DB::connection()->getDriverName();

        if ($actual !== 'sqlite') {
            $this->fail("Refusing to run tests: the live connection reports driver [{$actual}], not sqlite.");
        }
    }

    /**
     * POST a Meta webhook the way Meta actually does — signed.
     *
     * Every WhatsApp/Instagram delivery carries an X-Hub-Signature-256 HMAC of
     * the RAW body under the app secret, and the drivers now refuse anything
     * without a valid one. Tests have to sign too, or they are testing a
     * request Meta would never send.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedMetaWebhook(string $url, array $payload, ?string $appSecret = null): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = $appSecret ?? ChannelFactory::APP_SECRET;

        return $this->call(
            'POST',
            $url,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ],
            $body,
        );
    }
}
