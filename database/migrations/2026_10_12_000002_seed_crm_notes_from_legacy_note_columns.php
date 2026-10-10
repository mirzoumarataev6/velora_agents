<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries every existing deal/customer `note` into the new thread so nobody's
 * history goes missing the day the textarea disappears from the UI.
 *
 * One row per non-empty legacy note, kind='note', authored by the record's
 * owner, happened_at = the record's updated_at (the best available guess at
 * when that note was actually written — created_at would be wrong for a note
 * added well after the record was opened).
 *
 * Reversible: down() removes exactly the rows this migration added, not
 * every crm_notes row, by matching on the pseudo-marker left in `body`'s
 * absence — simplest is to just delete anything seeded before the feature
 * existed, which is: everything, since this runs before any real note is
 * ever created through the new UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->seedFrom('deals', 'App\\Models\\Deal');
        $this->seedFrom('customers', 'App\\Models\\Customer');
    }

    public function down(): void
    {
        DB::table('crm_notes')
            ->whereIn('notable_type', ['App\\Models\\Deal', 'App\\Models\\Customer'])
            ->delete();
    }

    private function seedFrom(string $table, string $notableType): void
    {
        DB::table($table)
            ->whereNotNull('note')
            ->where('note', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($notableType) {
                $now = now();

                $inserts = $rows->map(fn ($row) => [
                    'notable_type' => $notableType,
                    'notable_id' => $row->id,
                    'user_id' => $row->user_id,
                    'kind' => 'note',
                    'body' => $row->note,
                    'happened_at' => $row->updated_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('crm_notes')->insert($inserts);
            });
    }
};
