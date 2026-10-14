<?php

namespace App\Services\Account;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account the way `$user->delete()` alone cannot, on MySQL.
 *
 * `deals`/`customers` hold team-keyed composite RESTRICT foreign keys against
 * `crm_workspaces`/`crm_stages`. Stage 2d-5b retired the earlier user-keyed
 * FKs, but the cascade-order problem is unchanged: the team-keyed FKs still
 * reject deleting a referenced workspace or stage before its child rows.
 * All four tables are direct `CASCADE` children of `users.id`. A plain
 * `$user->delete()` leaves MySQL's own (undocumented, not portable) cascade
 * order to decide whether `crm_stages`/`crm_workspaces` get visited before
 * or after `deals`/`customers` — and on this schema, empirically, it visits
 * them first, which trips the RESTRICT: a `deals`/`customers` row still
 * references the `crm_stages`/`crm_workspaces` row MySQL is trying to
 * delete out from under it. SQLite happens to visit them in the safe order
 * today, which is why `AccountDeletionTest` passes there — that is an
 * accident of table-registration history (see Stage 2d-2's migrations for
 * the same hazard reproduced by a schema change alone), not a guarantee.
 *
 * The fix is ordering, not schema: physically delete `deals` then
 * `customers` — the RESTRICT side — before anything else runs, so by the
 * time the normal cascade (via the final `$user->delete()`) reaches
 * `crm_stages`/`crm_workspaces`, nothing references them any more and the
 * RESTRICT has nothing to block.
 *
 * `forceDelete()`, not `delete()`: both models carry SoftDeletes
 * (`2026_10_11_000001_add_soft_deletes_to_crm_tables.php`), and a soft
 * delete only sets `deleted_at` — the row, and its `crm_stage_id`/
 * `crm_workspace_id`, stays exactly where it was, which the RESTRICT check
 * still sees. `Builder::forceDelete()` also bypasses the soft-delete scope
 * entirely, so an already-soft-deleted deal or customer (still holding the
 * same references) is swept up too. Neither model has a `deleting`/`deleted`
 * hook this bypasses: `DealObserver` only listens for `created`/`updated`;
 * `Customer::booted()`'s `deleting` hook already no-ops under
 * `isForceDeleting()` (it exists to null `phone_key` on a SOFT delete only
 * — see that method's own docblock).
 *
 * `bi_sources.bi_connection_id -> bi_connections.id` is the same shape
 * (RESTRICT, both tables direct `CASCADE` children of `users.id`) but
 * needs no new code here: `UserObserver::deleting()` (unchanged) already
 * deletes every `BiSource` through Eloquent before this method's own
 * `$user->delete()` call even issues its SQL — and `BiSource` carries no
 * SoftDeletes, so that delete is already physical. That ordering is an
 * existing guarantee of Eloquent's `deleting` event firing before the
 * model's own DELETE statement, not something this service controls, so
 * it is listed in `HANDLED_RESTRICT_FKS` as "resolved elsewhere" rather
 * than re-implemented.
 *
 * `usage_logs` is a second, differently-shaped hazard found only by running
 * this against real data: `usage_logs.user_id` is `CASCADE` straight to
 * `users`, and `usage_logs.agent_id` is `SET NULL` to `agents` — itself a
 * direct `CASCADE` child of `users`. Deleting a user with at least one agent
 * that has a `usage_logs` row still fails on MySQL with a *different* error
 * (`1452` against `usage_logs_user_id_foreign`, on a plain
 * `DELETE FROM users`) even once deals/customers are out of the way: MySQL
 * deletes the `users` row from its own table before it finishes cascading
 * into `agents`, and the `agents` cascade's `SET NULL` write to
 * `usage_logs.agent_id` re-validates *every* FK on that row while rewriting
 * its secondary index — including `usage_logs_user_id_foreign`, against a
 * `users` row that, from inside this nested step, no longer exists. Same
 * family of bug as the RESTRICT one (MySQL's cascade-visit order deciding
 * whether a row it is about to touch still has something pointing at a row
 * it already removed), different trigger (`SET NULL` re-validating an
 * unrelated FK, not `RESTRICT` blocking one) — see
 * `AccountDeletionGuardsTest::test_every_set_null_restrict_or_no_action_fk_on_a_table_with_a_direct_edge_to_users_is_accounted_for`
 * for the general shape and why most of this class's other instances
 * (`deals.agent_id`, `customer_merges.*_customer_id`,
 * `bi_dashboards.bi_*_id`, …) are already safe for a different reason: the
 * table on the *parent* side of each of those is deleted by this method or
 * `UserObserver` as its own standalone statement, before `$user->delete()`
 * ever runs — so the `SET NULL` it triggers happens while `users` is still
 * fully intact, not nested inside the cascade that eventually removes it.
 * `usage_logs` has no such earlier removal of its own, which is why it is
 * deleted explicitly below. `UsageLog` carries no SoftDeletes and no
 * observer, so an ordinary bulk `delete()` is already physical and fires
 * nothing to skip.
 */
class DeletesAccount
{
    /**
     * Direct user_id FK children whose rows can belong to an explicit team.
     * A departing member may have left team_user already, so membership alone
     * cannot tell whether deleting that user would cascade away team data.
     * AccountDeletionGuardsTest checks this inventory against the schema.
     */
    public const ATTRIBUTED_TEAM_TABLES = [
        'agents', 'usage_logs', 'deals', 'bi_sources', 'bi_datasets',
        'bi_columns', 'bi_dashboards', 'bi_widgets', 'bi_connections',
        'bi_chat_messages', 'deal_activities', 'tasks', 'customers', 'crm_workspaces',
        'crm_stages', 'crm_notes', 'customer_merges',
    ];

    /**
     * Every RESTRICT/NO ACTION foreign key, between two tables that are
     * BOTH reachable by an unbroken CASCADE chain from `users`, that this
     * class is responsible for.
     * `tests/Feature/Services/Account/AccountDeletionGuardsTest` cross-checks
     * this list against the live schema and fails, naming the constraint,
     * if a new one like this is ever added without updating either this
     * list or `delete()` itself.
     *
     * @var list<array{table: string, columns: list<string>, foreign_table: string, resolution: string}>
     */
    public const HANDLED_RESTRICT_FKS = [
        [
            'table' => 'bi_sources',
            'columns' => ['bi_connection_id'],
            'foreign_table' => 'bi_connections',
            'resolution' => 'resolved elsewhere: UserObserver::deleting() already deletes every BiSource (not SoftDeletes) before $user->delete()\'s own DELETE statement',
        ],
        // Stage 2d-3-2's team-keyed composite FKs remain after 2d-5b
        // retires the user-keyed ones; their RESTRICT behavior still needs
        // these explicit child deletes before the users cascade runs.
        [
            'table' => 'deals',
            'columns' => ['crm_stage_id', 'team_id'],
            'foreign_table' => 'crm_stages',
            'resolution' => 'explicit: Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'deals',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'explicit: Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'customers',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'explicit: Customer::forceDelete() before $user->delete()',
        ],
    ];

    /**
     * Every foreign key, on a table that itself has a direct foreign key to
     * `users`, whose OTHER foreign key points at a table reachable from
     * `users` by an unbroken CASCADE chain and whose own delete rule is
     * SET NULL, RESTRICT or NO ACTION — the general shape of this bug class,
     * `HANDLED_RESTRICT_FKS` above being the RESTRICT-only special case this
     * class was first written for.
     *
     * `tests/Feature/Services/Account/AccountDeletionGuardsTest` cross-checks
     * this list against the live schema the same way it cross-checks
     * `HANDLED_RESTRICT_FKS`, and fails, naming the constraint, if a new one
     * appears without an entry here.
     *
     * @var list<array{table: string, columns: list<string>, foreign_table: string, resolution: string}>
     */
    public const KNOWN_DIAMOND_FKS = [
        // The RESTRICT ones are handled (and explained) above; repeated
        // here so this list is a complete answer to "is this diamond safe",
        // not just the SET NULL ones.
        [
            'table' => 'deals',
            'columns' => ['crm_stage_id', 'team_id'],
            'foreign_table' => 'crm_stages',
            'resolution' => 'see HANDLED_RESTRICT_FKS: explicit, Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'deals',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'see HANDLED_RESTRICT_FKS: explicit, Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'customers',
            'columns' => ['crm_workspace_id', 'team_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'see HANDLED_RESTRICT_FKS: explicit, Customer::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'bi_sources',
            'columns' => ['bi_connection_id'],
            'foreign_table' => 'bi_connections',
            'resolution' => 'see HANDLED_RESTRICT_FKS: resolved elsewhere, UserObserver deletes every BiSource standalone first',
        ],
        // SET NULL diamonds that are safe because the table on the PARENT
        // side of the SET NULL is deleted as its own standalone statement —
        // by this method or by UserObserver's chain — before $user->delete()
        // is ever called, i.e. while `users` is still fully intact. The SET
        // NULL these trigger therefore never runs nested inside the cascade
        // that eventually removes `users`, which is the specific condition
        // that makes usage_logs.agent_id (below) unsafe.
        [
            'table' => 'deals',
            'columns' => ['agent_id'],
            'foreign_table' => 'agents',
            'resolution' => 'resolved elsewhere: Deal::forceDelete() runs before $user->delete(); the row is gone before agents cascade-deletes',
        ],
        [
            'table' => 'deals',
            'columns' => ['conversation_id'],
            'foreign_table' => 'conversations',
            'resolution' => 'resolved elsewhere: Deal::forceDelete() runs before $user->delete(); the row is gone before conversations cascade-deletes',
        ],
        [
            'table' => 'deals',
            'columns' => ['lead_id'],
            'foreign_table' => 'leads',
            'resolution' => 'resolved elsewhere: Deal::forceDelete() runs before $user->delete(); the row is gone before leads cascade-deletes',
        ],
        [
            'table' => 'customer_merges',
            'columns' => ['from_customer_id'],
            'foreign_table' => 'customers',
            'resolution' => 'resolved elsewhere: Customer::forceDelete() runs as its own standalone statement before $user->delete(); the SET NULL it triggers fires while users still exists',
        ],
        [
            'table' => 'customer_merges',
            'columns' => ['into_customer_id'],
            'foreign_table' => 'customers',
            'resolution' => 'resolved elsewhere: Customer::forceDelete() runs as its own standalone statement before $user->delete(); the SET NULL it triggers fires while users still exists',
        ],
        [
            'table' => 'bi_dashboards',
            'columns' => ['bi_dataset_id'],
            'foreign_table' => 'bi_datasets',
            'resolution' => 'resolved elsewhere: BiDatasetObserver deletes the dataset as its own standalone statement (via UserObserver -> BiSourceObserver) before $user->delete()',
        ],
        [
            'table' => 'bi_dashboards',
            'columns' => ['bi_source_id'],
            'foreign_table' => 'bi_sources',
            'resolution' => 'resolved elsewhere: UserObserver deletes every BiSource as its own standalone statement before $user->delete()',
        ],
        // The one genuine fix this class required: nothing deletes
        // usage_logs standalone before $user->delete(), so its SET NULL
        // runs nested inside the agents cascade — see this class's docblock.
        [
            'table' => 'usage_logs',
            'columns' => ['agent_id'],
            'foreign_table' => 'agents',
            'resolution' => 'explicit: UsageLog::where(user_id)->delete() before $user->delete()',
        ],
    ];

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Lock the parent row before checking its children. InnoDB FK
            // inserts take a lock on it too, so a simultaneous write cannot
            // slip in after this check and then be cascade-deleted.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($this->hasExplicitTeamDependants($user)) {
                throw new SharedTeamAccountDeletionException;
            }

            $personalTeamIds = Team::where('created_by', $user->id)
                ->where('is_personal', true)->pluck('id');

            Deal::where('user_id', $user->id)->forceDelete();
            Customer::where('user_id', $user->id)->forceDelete();

            // usage_logs.agent_id is SET NULL to agents, a direct CASCADE
            // child of users — if left to the ordinary cascade, that SET
            // NULL runs nested inside the agents cascade, after users'
            // own row is already gone, and MySQL re-validates every FK on
            // the usage_logs row it is rewriting, including the unrelated
            // usage_logs_user_id_foreign, against a users row that — from
            // inside this nested step — no longer exists. See this class's
            // docblock. UsageLog has no SoftDeletes and no observer, so a
            // plain bulk delete is already physical.
            UsageLog::where('user_id', $user->id)->delete();

            // Every other table — agents (and, through it, channels,
            // conversations, knowledge, leads, ...), crm_workspaces,
            // crm_stages, tasks, crm_notes, customer_merges, deal_activities,
            // the bi_* tables, team_user — still goes through the ordinary
            // cascade this triggers, with UserObserver/BiSourceObserver/
            // BiDatasetObserver's chain (and the DropDatasetTableJob it
            // queues) running exactly as it does today.
            $user->delete();

            // The personal team is only an internal tenant namespace. Its
            // created_by FK becomes NULL when the user goes away; deleting
            // it now avoids leaving an ownerless shell and orphan roles.
            Team::whereIn('id', $personalTeamIds)->delete();
        });
    }

    private function hasExplicitTeamDependants(User $user): bool
    {
        // A team creator cannot disappear while their collaborative team
        // still exists, even when it has no content yet. Invitations also
        // retain a non-null inviter FK and need an ownership handoff first.
        if (Team::where('created_by', $user->id)->where('is_personal', false)->exists()
            || Invitation::where('invited_by', $user->id)->exists()) {
            return true;
        }

        // A board can be empty, so the attributed task inventory below would
        // miss it. Letting its creator go would SET NULL created_by and make
        // a private board ownerless before anyone arranges a handoff.
        if (DB::table('project_boards')->join('teams', 'teams.id', '=', 'project_boards.team_id')
            ->where('project_boards.created_by', $user->id)
            ->where('teams.is_personal', false)->exists()) {
            return true;
        }

        foreach (self::ATTRIBUTED_TEAM_TABLES as $table) {
            if (DB::table($table)
                ->join('teams', 'teams.id', '=', $table.'.team_id')
                ->where($table.'.user_id', $user->id)
                ->where('teams.is_personal', false)
                ->exists()) {
                return true;
            }
        }

        return false;
    }
}
