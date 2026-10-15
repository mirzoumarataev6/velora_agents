<?php

namespace Tests\Unit\Projects;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * U-01: the occupancy count inside Task::assertColumnHasRoom() must be a
 * locking read, not a plain consistent one. A plain COUNT takes InnoDB's
 * REPEATABLE READ snapshot at the transaction's first consistent read — if
 * an earlier non-locking read in the same transaction (e.g. CRM task
 * creation's own lookups, which run before the column lock) already fixed
 * that snapshot, the count could still miss a concurrent transaction that
 * committed an occupant in between. A locking read (lockForUpdate()) is
 * documented to always see the latest committed data regardless of when
 * the snapshot was taken, which is the only reason the column lock a few
 * lines above means anything either.
 *
 * This whole suite runs on sqlite (phpunit.xml forces it), and
 * SQLiteGrammar::compileLock() unconditionally returns '' regardless of
 * whether lockForUpdate() was ever called — sqlite has a different MVCC
 * model entirely and silently drops the clause from the SQL string either
 * way, so no query-log assertion on this connection could tell a fixed
 * call site apart from a broken one. Two different proofs stand in for it:
 *
 * 1. A source-level check that the exact statement building the occupancy
 *    query calls lockForUpdate() — this is what actually catches a
 *    regression (a future edit that drops the call) and is why this is the
 *    assertion that must fail on the pre-fix commit.
 * 2. That the identical query shape, once confirmed to carry the call,
 *    compiles a real MySQL FOR UPDATE clause under MySqlGrammar — obtained
 *    from the app's own 'mysql' connection config without opening a real
 *    connection (Grammar::compileSelect() never touches the PDO, which
 *    Laravel resolves lazily). This is the "asserting the compiled SQL"
 *    half the task asked for; (1) is what makes it mean anything.
 *
 * Concurrency itself — whether this really closes the race under
 * genuinely simultaneous MySQL connections — is NOT proven by this test or
 * anywhere else in this suite; see the Stage D1 report.
 */
class TaskWipLimitOccupancyLockTest extends TestCase
{
    public function test_the_occupancy_count_statement_locks_and_compiles_a_for_update_clause_under_mysql(): void
    {
        $method = new ReflectionMethod(Task::class, 'assertColumnHasRoom');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertMatchesRegularExpression(
            '/\$occupants\s*=\s*static::where\([^;]*?->lockForUpdate\(\)/s',
            $body,
            'The $occupants query in Task::assertColumnHasRoom() must call lockForUpdate() — '.
            'a plain COUNT can read a stale REPEATABLE READ snapshot (U-01).',
        );

        // Having confirmed the real statement carries the call, show what
        // that call actually does to the SQL under MySQL's grammar — the
        // same WHERE shape assertColumnHasRoom() builds its occupancy
        // query from.
        $query = Task::query()
            ->where('project_column_id', 42)
            ->whereNull('archived_at')
            ->whereKeyNot(7)
            ->lockForUpdate();

        $builder = $query->getQuery();
        $builder->grammar = DB::connection('mysql')->getQueryGrammar();

        $sql = strtolower($builder->toSql());

        $this->assertStringContainsString('for update', $sql);
        $this->assertStringContainsString('`tasks`', $sql);
        $this->assertStringContainsString('`project_column_id` = ?', $sql);
        $this->assertStringContainsString('`archived_at` is null', $sql);
    }
}
