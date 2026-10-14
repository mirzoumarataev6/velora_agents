<?php

namespace Tests\Feature\Services\Account;

use App\Jobs\Bi\DropDatasetTableJob;
use App\Models\Agent;
use App\Models\BiConnection;
use App\Models\BiSource;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use App\Services\Account\DeletesAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Three permanent guards against this class of bug rotting back in:
 *
 * (a) every RESTRICT/NO ACTION foreign key between two tables that are
 *     both cascade-descendants of `users` must be named in
 *     DeletesAccount::HANDLED_RESTRICT_FKS — a new composite RESTRICT
 *     added without updating either the list or delete() itself fails
 *     this test, naming the constraint.
 * (b) the actual DELETE statements DeletesAccount issues must visit
 *     deals/customers before crm_stages/crm_workspaces — this is what
 *     fails on SQLite the moment the order in delete() is wrong, long
 *     before anyone gets to try it against MySQL.
 * (c) a failure partway through must leave nothing deleted and must
 *     never dispatch the table-drop job for data that still exists.
 * (d) the general shape (a) is a special case of: on a table with a direct
 *     foreign key to `users`, every OTHER foreign key pointing at a
 *     cascade-descendant of `users` whose delete rule is SET NULL,
 *     RESTRICT or NO ACTION must be named in
 *     DeletesAccount::KNOWN_DIAMOND_FKS — this is what caught
 *     usage_logs.agent_id (SET NULL to agents), found only by running the
 *     fix against real MySQL data, where it failed with a different error
 *     than the RESTRICT one despite being the same family of bug.
 */
class AccountDeletionGuardsTest extends TestCase
{
    use RefreshDatabase;

    // --- (a) schema cross-check -------------------------------------------

    /**
     * Every table reachable from `users` by an unbroken chain of
     * ON DELETE CASCADE foreign keys — i.e. every table a plain
     * $user->delete() cascades into, directly or indirectly.
     *
     * @return list<string>
     */
    private function cascadeDescendantsOfUsers(): array
    {
        $tables = collect(Schema::getTables())->pluck('name')->all();

        $cascadeParentsOf = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if ($fk['on_delete'] === 'cascade') {
                    $cascadeParentsOf[$table][] = $fk['foreign_table'];
                }
            }
        }

        $descendants = [];
        $frontier = ['users'];

        while ($frontier !== []) {
            $next = [];
            foreach ($cascadeParentsOf as $child => $parents) {
                if (isset($descendants[$child])) {
                    continue;
                }
                if (array_intersect($frontier, $parents) !== []) {
                    $descendants[$child] = true;
                    $next[] = $child;
                }
            }
            $frontier = $next;
        }

        return array_keys($descendants);
    }

    public function test_every_restrict_fk_between_two_cascade_descendants_of_users_is_covered(): void
    {
        $descendants = $this->cascadeDescendantsOfUsers();

        // Sanity: this must actually find the tables the whole class is
        // built around, or the BFS above is broken, not the production code.
        $this->assertContains('deals', $descendants);
        $this->assertContains('crm_stages', $descendants);
        $this->assertContains('crm_workspaces', $descendants);
        $this->assertContains('customers', $descendants);
        $this->assertContains('bi_sources', $descendants);
        $this->assertContains('bi_connections', $descendants);

        $handled = DeletesAccount::HANDLED_RESTRICT_FKS;

        foreach ($descendants as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (! in_array($fk['on_delete'], ['restrict', 'no action'], true)) {
                    continue;
                }

                if (! in_array($fk['foreign_table'], $descendants, true)) {
                    continue;
                }

                $columns = $fk['columns'];
                sort($columns);

                $isHandled = collect($handled)->contains(function (array $entry) use ($table, $columns, $fk) {
                    $entryColumns = $entry['columns'];
                    sort($entryColumns);

                    return $entry['table'] === $table
                        && $entryColumns === $columns
                        && $entry['foreign_table'] === $fk['foreign_table'];
                });

                $this->assertTrue($isHandled, sprintf(
                    'Uncovered %s foreign key: %s.(%s) -> %s. Add it to DeletesAccount::HANDLED_RESTRICT_FKS and handle it in delete().',
                    $fk['on_delete'],
                    $table,
                    implode(',', $fk['columns']),
                    $fk['foreign_table'],
                ));
            }
        }
    }

    public function test_account_deletion_fk_inventories_have_no_stale_constraints(): void
    {
        // The coverage scans above only detect a new FK missing from an
        // inventory. Stage 2d-5b removes three FKs, so the reverse check
        // matters too: otherwise an obsolete entry looks like protection
        // that the database no longer provides.
        foreach ([DeletesAccount::HANDLED_RESTRICT_FKS, DeletesAccount::KNOWN_DIAMOND_FKS] as $inventory) {
            foreach ($inventory as $entry) {
                $expectedColumns = $entry['columns'];
                sort($expectedColumns);

                $exists = collect(Schema::getForeignKeys($entry['table']))->contains(function ($fk) use ($entry, $expectedColumns) {
                    $columns = $fk['columns'];
                    sort($columns);

                    return $columns === $expectedColumns && $fk['foreign_table'] === $entry['foreign_table'];
                });

                $this->assertTrue($exists, sprintf(
                    'Stale account-deletion FK inventory entry: %s.(%s) -> %s.',
                    $entry['table'],
                    implode(',', $entry['columns']),
                    $entry['foreign_table'],
                ));
            }
        }
    }

    /**
     * Every table that has a foreign key pointing directly at `users`,
     * regardless of that foreign key's own delete rule.
     *
     * @return list<string>
     */
    private function tablesWithDirectForeignKeyToUsers(): array
    {
        $tables = collect(Schema::getTables())->pluck('name')->all();

        $result = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if ($fk['foreign_table'] === 'users') {
                    $result[] = $table;

                    break;
                }
            }
        }

        return $result;
    }

    public function test_every_team_row_attributed_to_a_user_is_checked_before_account_deletion(): void
    {
        $actual = [];
        foreach ($this->tablesWithDirectForeignKeyToUsers() as $table) {
            if ($table === 'team_user' || ! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            $hasAttributedCascade = collect(Schema::getForeignKeys($table))->contains(fn (array $fk) => $fk['foreign_table'] === 'users'
                && in_array('user_id', $fk['columns'], true)
                && $fk['on_delete'] === 'cascade'
            );

            if ($hasAttributedCascade) {
                $actual[] = $table;
            }
        }

        sort($actual);
        $guarded = DeletesAccount::ATTRIBUTED_TEAM_TABLES;
        sort($guarded);

        // A missing table would silently erase another member's work when
        // the attributed user leaves, even if team_user was removed earlier.
        $this->assertSame($actual, $guarded);
    }

    public function test_every_set_null_restrict_or_no_action_fk_on_a_table_with_a_direct_edge_to_users_is_accounted_for(): void
    {
        $descendants = $this->cascadeDescendantsOfUsers();
        $directToUsers = $this->tablesWithDirectForeignKeyToUsers();

        // Sanity: the real schema must actually contain the pairing this
        // guard exists for, or the scan above is broken, not the production
        // code.
        $this->assertContains('usage_logs', $directToUsers);
        $this->assertContains('agents', $descendants);

        $known = DeletesAccount::KNOWN_DIAMOND_FKS;

        foreach ($directToUsers as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if ($fk['foreign_table'] === 'users') {
                    continue;
                }

                if (! in_array($fk['on_delete'], ['set null', 'restrict', 'no action'], true)) {
                    continue;
                }

                if (! in_array($fk['foreign_table'], $descendants, true)) {
                    continue;
                }

                $columns = $fk['columns'];
                sort($columns);

                $isKnown = collect($known)->contains(function (array $entry) use ($table, $columns, $fk) {
                    $entryColumns = $entry['columns'];
                    sort($entryColumns);

                    return $entry['table'] === $table
                        && $entryColumns === $columns
                        && $entry['foreign_table'] === $fk['foreign_table'];
                });

                $this->assertTrue($isKnown, sprintf(
                    'Unaccounted-for %s diamond: %s.(%s) -> %s, on a table with a direct edge to users. '.
                    'Add it to DeletesAccount::KNOWN_DIAMOND_FKS with how it is handled or why it is already safe.',
                    $fk['on_delete'],
                    $table,
                    implode(',', $fk['columns']),
                    $fk['foreign_table'],
                ));
            }
        }
    }

    // --- (b) statement order -----------------------------------------------

    private function fullAccount(): array
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $workspace = CrmWorkspace::defaultFor($user);
        $stage = CrmStage::initialFor($user);
        $customer = Customer::factory()->for($user)->create(['crm_workspace_id' => $workspace->id]);
        $deal = Deal::factory()->for($user)->create([
            'customer_id' => $customer->id,
            'crm_workspace_id' => $workspace->id,
            'crm_stage_id' => $stage->id,
            'agent_id' => $agent->id,
        ]);
        $connection = BiConnection::factory()->create(['user_id' => $user->id]);
        $source = BiSource::factory()->create([
            'user_id' => $user->id,
            'kind' => BiSource::KIND_CONNECTION,
            'bi_connection_id' => $connection->id,
        ]);

        return compact('user', 'agent', 'workspace', 'stage', 'customer', 'deal', 'connection', 'source');
    }

    public function test_deals_and_customers_are_deleted_before_crm_stages_and_crm_workspaces(): void
    {
        $fixture = $this->fullAccount();

        $deletes = [];
        DB::listen(function ($query) use (&$deletes) {
            if (stripos($query->sql, 'delete from') === 0) {
                $deletes[] = $query->sql;
            }
        });

        app(DeletesAccount::class)->delete($fixture['user']);

        $indexOfFirstMatching = function (string $needle) use ($deletes) {
            foreach ($deletes as $i => $sql) {
                if (stripos($sql, $needle) !== false) {
                    return $i;
                }
            }

            return null;
        };

        $dealsAt = $indexOfFirstMatching('"deals"') ?? $indexOfFirstMatching('`deals`');
        $customersAt = $indexOfFirstMatching('"customers"') ?? $indexOfFirstMatching('`customers`');
        $stagesAt = $indexOfFirstMatching('"crm_stages"') ?? $indexOfFirstMatching('`crm_stages`');
        $workspacesAt = $indexOfFirstMatching('"crm_workspaces"') ?? $indexOfFirstMatching('`crm_workspaces`');

        $this->assertNotNull($dealsAt, 'No DELETE against deals was issued at all.');
        $this->assertNotNull($customersAt, 'No DELETE against customers was issued at all.');

        if ($stagesAt !== null) {
            $this->assertLessThan($stagesAt, $dealsAt, 'deals must be deleted before crm_stages.');
        }

        if ($workspacesAt !== null) {
            $this->assertLessThan($workspacesAt, $dealsAt, 'deals must be deleted before crm_workspaces.');
            $this->assertLessThan($workspacesAt, $customersAt, 'customers must be deleted before crm_workspaces.');
        }
    }

    // --- (c) atomicity -------------------------------------------------------

    public function test_a_failure_partway_through_leaves_nothing_deleted_and_never_dispatches_the_drop_job(): void
    {
        Queue::fake();

        $fixture = $this->fullAccount();

        Event::listen('eloquent.deleting: '.User::class, function () {
            throw new RuntimeException('simulated failure deep in the delete');
        });

        try {
            app(DeletesAccount::class)->delete($fixture['user']);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException|QueryException $e) {
            // Either the raw RuntimeException (no transaction bubbling) or a
            // QueryException if the driver wraps it — either way the
            // transaction must not have committed.
        }

        $this->assertDatabaseHas('users', ['id' => $fixture['user']->id]);
        $this->assertDatabaseHas('deals', ['id' => $fixture['deal']->id]);
        $this->assertDatabaseHas('customers', ['id' => $fixture['customer']->id]);
        $this->assertDatabaseHas('bi_sources', ['id' => $fixture['source']->id]);
        $this->assertDatabaseHas('crm_workspaces', ['id' => $fixture['workspace']->id]);
        $this->assertDatabaseHas('crm_stages', ['id' => $fixture['stage']->id]);

        Queue::assertNotPushed(DropDatasetTableJob::class);
    }
}
