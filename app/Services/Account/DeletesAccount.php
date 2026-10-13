<?php

namespace App\Services\Account;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account the way `$user->delete()` alone cannot, on MySQL.
 *
 * `deals`/`customers` hold composite RESTRICT foreign keys against
 * `crm_workspaces`/`crm_stages` — `deals_user_stage_fk`, `deals_user_workspace_fk`
 * (`2026_10_14_000001_create_crm_stages_table.php`,
 * `2026_10_06_000003_create_crm_workspaces_table.php`) and
 * `customers_user_workspace_fk` (`2026_10_06_000004_add_crm_workspace_to_customers.php`).
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
 */
class DeletesAccount
{
    /**
     * Every RESTRICT/NO ACTION foreign key, between two tables that are
     * BOTH reachable by an unbroken CASCADE chain from `users`, that this
     * class is responsible for. `tests/Feature/Settings/AccountDeletionOrderTest`
     * cross-checks this list against the live schema and fails, naming the
     * constraint, if a new one like this is ever added without updating
     * either this list or `delete()` itself.
     *
     * @var list<array{table: string, columns: list<string>, foreign_table: string, resolution: string}>
     */
    public const HANDLED_RESTRICT_FKS = [
        [
            'table' => 'deals',
            'columns' => ['crm_stage_id', 'user_id'],
            'foreign_table' => 'crm_stages',
            'resolution' => 'explicit: Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'deals',
            'columns' => ['crm_workspace_id', 'user_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'explicit: Deal::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'customers',
            'columns' => ['crm_workspace_id', 'user_id'],
            'foreign_table' => 'crm_workspaces',
            'resolution' => 'explicit: Customer::forceDelete() before $user->delete()',
        ],
        [
            'table' => 'bi_sources',
            'columns' => ['bi_connection_id'],
            'foreign_table' => 'bi_connections',
            'resolution' => 'resolved elsewhere: UserObserver::deleting() already deletes every BiSource (not SoftDeletes) before $user->delete()\'s own DELETE statement',
        ],
    ];

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            Deal::where('user_id', $user->id)->forceDelete();
            Customer::where('user_id', $user->id)->forceDelete();

            // Every other table — agents (and, through it, channels,
            // conversations, knowledge, leads, ...), usage_logs,
            // crm_workspaces, crm_stages, tasks, crm_notes, customer_merges,
            // deal_activities, the bi_* tables, team_user — still goes
            // through the ordinary cascade this triggers, with
            // UserObserver/BiSourceObserver/BiDatasetObserver's chain
            // (and the DropDatasetTableJob it queues) running exactly as
            // it does today.
            $user->delete();
        });
    }
}
