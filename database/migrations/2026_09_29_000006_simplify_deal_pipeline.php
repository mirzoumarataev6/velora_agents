<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three stages instead of six: Новый лид → Торг → Оплата, plus the
 * always-separate Отменено. Оплата is redefined too — it now means the
 * money actually arrived, not "an order exists and is awaiting payment",
 * which is what it meant before this migration. Nothing in this system
 * can observe an actual payment, so from here on Оплата is manual-only;
 * the automation that used to land a deal there on an order or an
 * approved price no longer does (see DealStageAutomation).
 *
 * `closed_at` is the new "Закрыто" flag Оплата needed once it stopped
 * being a pipeline step a deal simply arrives at and became a state the
 * owner has to attest to. A nullable timestamp, not a boolean — this
 * app already treats "done" as a WHEN rather than a flag everywhere else
 * (Task::done_at), and a closed date is worth keeping for the same reason
 * a completion date always is.
 *
 * EXISTING DATA. The six old stages do not map onto the three new ones by
 * name, and guessing wrong here would silently mark unpaid deals as paid.
 * The conservative reading, deal by deal:
 *
 *   new_lead, messaging  -> new_lead   (Переписка was never more than "a
 *                                        real exchange happened", which new_lead
 *                                        already covers on its own now)
 *   haggling             -> haggling   (unchanged)
 *   payment              -> haggling   (this used to mean "order placed /
 *                                        price agreed, awaiting payment" —
 *                                        exactly what Оплата must NOT mean
 *                                        any more, so it lands one step
 *                                        short rather than being relabelled paid)
 *   delivery, done        -> payment + closed_at = stage_changed_at
 *                                       (both used to mean payment had
 *                                        already happened — delivery even
 *                                        implies it shipped after — so
 *                                        marking these paid AND closed is
 *                                        the reading closest to what the
 *                                        data already asserted)
 *   cancelled             -> cancelled (unchanged)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('payment_status');
        });

        DB::table('deals')->where('stage', 'messaging')->update(['stage' => 'new_lead']);
        DB::table('deals')->where('stage', 'payment')->update(['stage' => 'haggling']);

        DB::table('deals')
            ->whereIn('stage', ['delivery', 'done'])
            ->update(['stage' => 'payment']);

        // A second pass sets closed_at from each row's own stage_changed_at
        // — can't be done in the update above, since that column varies per
        // row rather than being one literal value.
        DB::table('deals')
            ->where('stage', 'payment')
            ->whereNull('closed_at')
            ->orderBy('id')
            ->chunkById(300, function ($deals) {
                foreach ($deals as $deal) {
                    DB::table('deals')->where('id', $deal->id)
                        ->update(['closed_at' => $deal->stage_changed_at ?? $deal->updated_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
