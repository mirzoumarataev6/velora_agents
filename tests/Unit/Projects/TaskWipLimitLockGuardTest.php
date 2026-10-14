<?php

namespace Tests\Unit\Projects;

use App\Models\ProjectColumn;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Task::assertLockIsLive() is what stops the WIP-limit check's
 * lockForUpdate() from silently becoming decorative outside a transaction
 * (see that method's own docblock) — reached every time a task is created
 * or moved into a column that has a wip_limit.
 *
 * This cannot be exercised through any HTTP request or Eloquent call in the
 * rest of the suite: RefreshDatabase wraps every Feature test in its own
 * transaction, so DB::transactionLevel() is never 0 there regardless of
 * whether the call site under test opens one of its own — the one case
 * this guard exists to catch is exactly the one case the test harness
 * itself makes unreachable. Tested here instead, directly, against a
 * ProjectColumn built in memory (no row, no migration needed) with no
 * DB::transaction() active at all — this is a plain Unit test and
 * deliberately does not use RefreshDatabase.
 */
class TaskWipLimitLockGuardTest extends TestCase
{
    private function assertLockIsLive(ProjectColumn $column): void
    {
        $method = new ReflectionMethod(Task::class, 'assertLockIsLive');
        $method->setAccessible(true);
        $method->invoke(null, $column);
    }

    public function test_it_throws_outside_a_transaction_when_the_column_has_a_wip_limit(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);

        $this->assertLockIsLive(new ProjectColumn(['wip_limit' => 3]));
    }

    public function test_it_does_not_throw_outside_a_transaction_when_the_column_has_no_wip_limit(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->assertLockIsLive(new ProjectColumn(['wip_limit' => null]));

        $this->addToAssertionCount(1);
    }

    public function test_it_does_not_throw_inside_a_transaction_even_with_a_wip_limit(): void
    {
        DB::transaction(function (): void {
            $this->assertLockIsLive(new ProjectColumn(['wip_limit' => 3]));
        });

        $this->addToAssertionCount(1);
    }
}
