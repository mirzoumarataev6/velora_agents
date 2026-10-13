<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2d-5b: a teammate's deal/customer can now reference the same team's
 * workspace or stage even when another member created it. The three old
 * (user_id, resource_id) FKs forbid that relationship; the three matching
 * (team_id, resource_id) FKs from 2d-3 already enforce tenant isolation.
 *
 * Drop the old FKs before their parent-side (user_id, id) unique indexes:
 * MySQL refuses to drop an index while an FK needs it. Both tables retain
 * their (team_id, id) unique indexes, checked before any DDL. On rollback,
 * reverse the order and reject cross-member references before adding any FK.
 */
return new class extends Migration
{
    /** @var list<array{old: string, replacement: string, table: string, columns: list<string>, parent: string}> */
    private const FKS = [
        ['old' => 'deals_user_workspace_fk', 'replacement' => 'deals_team_workspace_fk', 'table' => 'deals', 'columns' => ['user_id', 'crm_workspace_id'], 'parent' => 'crm_workspaces'],
        ['old' => 'deals_user_stage_fk', 'replacement' => 'deals_team_stage_fk', 'table' => 'deals', 'columns' => ['user_id', 'crm_stage_id'], 'parent' => 'crm_stages'],
        ['old' => 'customers_user_workspace_fk', 'replacement' => 'customers_team_workspace_fk', 'table' => 'customers', 'columns' => ['user_id', 'crm_workspace_id'], 'parent' => 'crm_workspaces'],
    ];

    /** @var list<array{table: string, old: string, replacement: string}> */
    private const INDEXES = [
        ['table' => 'crm_workspaces', 'old' => 'crm_workspaces_owner_id_unique', 'replacement' => 'crm_workspaces_team_id_unique'],
        ['table' => 'crm_stages', 'old' => 'crm_stages_owner_id_unique', 'replacement' => 'crm_stages_team_id_unique'],
    ];

    public function up(): void
    {
        // All checks precede all DDL: MySQL DDL commits implicitly, so a
        // missing final replacement must not leave the first FK retired.
        foreach (self::FKS as $fk) {
            $this->assertForeignKey($fk, false);
            $this->assertForeignKey($fk, true);
        }

        foreach (self::INDEXES as $index) {
            $this->assertIndex($index, false);
            $this->assertIndex($index, true);
        }

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach (self::FKS as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk, $isSqlite) {
                // SQLite discards FK names; Laravel identifies its FK by
                // columns when rebuilding the table. MySQL needs the name.
                $table->dropForeign($isSqlite ? $fk['columns'] : $fk['old']);
            });
        }

        foreach (self::INDEXES as $index) {
            Schema::table($index['table'], fn (Blueprint $table) => $table->dropUnique($index['old']));
        }
    }

    public function down(): void
    {
        foreach (self::FKS as $fk) {
            $this->assertNoCrossMemberReferences($fk);
        }

        foreach (self::INDEXES as $index) {
            Schema::table($index['table'], fn (Blueprint $table) => $table->unique(['user_id', 'id'], $index['old']));
        }

        foreach (self::FKS as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk) {
                $table->foreign($fk['columns'], $fk['old'])
                    ->references(['user_id', 'id'])
                    ->on($fk['parent'])
                    ->restrictOnDelete();
            });
        }
    }

    private function assertForeignKey(array $fk, bool $teamKeyed): void
    {
        $columns = [$teamKeyed ? 'team_id' : 'user_id', $fk['columns'][1]];
        $parentColumns = [$teamKeyed ? 'team_id' : 'user_id', 'id'];
        $name = $teamKeyed ? $fk['replacement'] : $fk['old'];

        $exists = collect(Schema::getForeignKeys($fk['table']))->contains(fn ($found) => $found['columns'] === $columns
            && $found['foreign_table'] === $fk['parent']
            && $found['foreign_columns'] === $parentColumns
            && $found['on_delete'] === 'restrict');

        if (! $exists) {
            throw new RuntimeException("{$fk['table']}: refusing to retire old CRM foreign keys — {$name} is missing or has the wrong shape.");
        }
    }

    private function assertIndex(array $index, bool $teamKeyed): void
    {
        $name = $teamKeyed ? $index['replacement'] : $index['old'];
        $columns = [$teamKeyed ? 'team_id' : 'user_id', 'id'];
        $exists = collect(Schema::getIndexes($index['table']))->contains(fn ($found) => $found['name'] === $name
            && $found['unique']
            && $found['columns'] === $columns);

        if (! $exists) {
            throw new RuntimeException("{$index['table']}: refusing to retire old CRM indexes — {$name} is missing or has the wrong shape.");
        }
    }

    private function assertNoCrossMemberReferences(array $fk): void
    {
        $resourceId = $fk['columns'][1];
        $count = DB::table($fk['table'])
            ->join($fk['parent'], "{$fk['table']}.{$resourceId}", '=', "{$fk['parent']}.id")
            ->whereColumn("{$fk['table']}.user_id", '!=', "{$fk['parent']}.user_id")
            ->count();

        if ($count > 0) {
            throw new RuntimeException("{$fk['old']}: {$count} cross-member reference(s) prevent rollback. Reassign those rows before restoring user-keyed foreign keys.");
        }
    }
};
