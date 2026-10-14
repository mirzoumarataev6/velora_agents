<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL-only cleanup, nothing else: `2026_10_21_000001` dropped the FK
 * constraint `deals_user_stage_fk` but never the index behind it.
 *
 * WHY THIS ONE FK AND NOT ITS TWO SIBLINGS. When that migration's three old
 * FKs were created, `deals_user_workspace_fk` and `customers_user_workspace_fk`
 * already had an explicit covering index at their own column pair as a
 * leftmost prefix (`deals_crm_workspace_origin_date_idx`,
 * `customers_workspace_contact_idx` — both predate the FK in the same
 * migration), so InnoDB reused it and created nothing extra. `deals_user_stage_fk`
 * (`2026_10_14_000001_create_crm_stages_table.php`) had no such sibling —
 * the only other index added there, `index(['user_id', 'stage_role'])`, does
 * not cover `(user_id, crm_stage_id)` — so InnoDB auto-created a supporting
 * index of its own, named after the constraint by default. Laravel's
 * `dropForeign()` only emits `DROP FOREIGN KEY`, never a matching
 * `DROP INDEX`, so that auto-created index has sat on
 * `deals(user_id, crm_stage_id)` ever since: read by nothing (the team-keyed
 * `deals_team_stage_fk` from `2026_10_19_000002` covers `(team_id,
 * crm_stage_id)` instead), and invisible to this suite's sqlite-only tests —
 * SQLite never auto-creates a same-named index behind a foreign key the way
 * InnoDB does, so there was nothing there to find on that driver either.
 *
 * MySQL-only AND conditional on purpose. SQLite never had this index, so
 * `Schema::hasIndex()` there is false and `up()` is a pure no-op without
 * needing its own driver branch for that half. The `hasIndex` check beyond
 * the driver guard is what makes re-running this safely idempotent — on a
 * database where the index was already removed by hand, or where it was
 * somehow never created at all, `up()` does nothing rather than erroring
 * on `DROP INDEX` of something not there.
 */
return new class extends Migration
{
    private const TABLE = 'deals';

    private const INDEX = 'deals_user_stage_fk';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }

    /**
     * Not restored. The index was never its own thing to begin with — it
     * was MySQL's side effect of a foreign key that no longer exists
     * either, and nothing reads `(user_id, crm_stage_id)` as a leftmost
     * pair any more (`deals_team_stage_fk` covers `(team_id,
     * crm_stage_id)` instead). Recreating a dead index on rollback would
     * just reintroduce the exact leftover this migration exists to
     * remove; a no-op is the honest `down()` for a migration whose only
     * job is deleting cruft nothing depends on.
     */
    public function down(): void
    {
        // Intentionally empty — see this file's own docblock.
    }
};
