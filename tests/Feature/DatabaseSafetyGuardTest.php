<?php

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The guard in Tests\TestCase that stops the suite dropping a real database.
 *
 * RefreshDatabase opens with migrate:fresh. When a cached config overrides
 * phpunit.xml's sqlite pin, that lands on the MySQL database from .env and
 * drops every table in it — which is exactly how the local `agent` database
 * was destroyed on 2026-08-21, with no binlog or dump to recover from.
 *
 * The guard is the thing standing between this suite and that outcome, so it
 * gets tests of its own. These provoke the dangerous conditions through config
 * only; no connection to a real database is ever opened.
 */
class DatabaseSafetyGuardTest extends TestCase
{
    /**
     * The happy path — the guard must stay silent under phpunit.xml's real
     * settings, or it would block every run.
     */
    public function test_it_allows_in_memory_sqlite(): void
    {
        $this->guardAgainstRunningOnARealDatabase();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_it_refuses_to_run_against_mysql(): void
    {
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.database', 'agent');

        try {
            $this->guardAgainstRunningOnARealDatabase();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('DROP EVERY TABLE', $e->getMessage());
            // The message has to name the escape hatch, or whoever hits this at
            // 2am has to go and read the source to find it.
            $this->assertStringContainsString('config:clear', $e->getMessage());
            $this->assertStringContainsString('agent', $e->getMessage());

            return;
        }

        $this->fail('The guard allowed the suite to run against MySQL.');
    }

    /**
     * A sqlite FILE is still somebody's data — only :memory: is disposable.
     */
    public function test_it_refuses_to_run_against_a_sqlite_file(): void
    {
        config()->set('database.connections.sqlite.database', database_path('database.sqlite'));

        try {
            $this->guardAgainstRunningOnARealDatabase();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('rather than :memory:', $e->getMessage());

            return;
        }

        $this->fail('The guard allowed the suite to run against a sqlite file.');
    }

    public function test_it_refuses_any_other_driver(): void
    {
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.driver', 'pgsql');
        config()->set('database.connections.pgsql.database', 'agent');

        $this->expectException(AssertionFailedError::class);

        $this->guardAgainstRunningOnARealDatabase();
    }
}
