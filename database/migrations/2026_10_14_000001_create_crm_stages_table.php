<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own pipeline, replacing the one hardcoded set of stages every
 * account used to share. Scoped by `user_id`, like `crm_workspaces` — the
 * same table shape, so the same future `organization_id` migration applies
 * unchanged.
 *
 * `role` is what the rest of the CRM keys special behaviour off now, not the
 * stage's name or position: exactly one stage per owner carries
 * 'initial' (where a deal opens), one carries 'won' (the revenue-counting
 * stage), one carries 'lost' (the cancelled terminal, set apart from the
 * ordered pipeline the same way `Deal::STAGE_CANCELLED` always was) — every
 * other stage carries a null role and is free to be renamed, recoloured,
 * reordered, added or removed by the owner. A role is fixed at the moment a
 * stage is created and can never change afterward (enforced in
 * `CrmStage::booted()`, a PHP-level invariant like `Customer::booted()`'s
 * phone-key guarantee — a partial unique index would mean different things
 * on SQLite (tests) and MySQL (prod), the same reasoning that keeps that
 * other invariant out of the schema too).
 *
 * `is_system` + a name that doubles as a translation key is how the four
 * seeded defaults stay localized until an owner actually touches them: a
 * fresh, never-customized account still reads "Новый лид"/"Торг"/"Oplata"/
 * "Отменено" in whichever locale the viewer is using, exactly as before this
 * table existed. The moment a stage is renamed (or created from scratch),
 * `is_system` drops to false and `name` becomes the literal, owner-typed
 * label — see `CrmStage::displayName()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('role', 16)->nullable();
            // Null only for the lost-role stage — deliberately excluded from
            // the ordered pipeline, the same way `Deal::STAGES` never
            // included `STAGE_CANCELLED`. Every other stage (initial,
            // intermediate, won) sits in one contiguous 0..N sequence.
            $table->unsignedInteger('position')->nullable();
            $table->string('color', 16)->default('slate');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Lets `deals.crm_stage_id` carry a COMPOSITE foreign key — see
            // `deals_user_stage_fk` below — so a buggy caller can never
            // attach one owner's deal to another owner's stage, the same
            // guard `crm_workspaces_owner_id_unique` gives workspaces.
            $table->unique(['user_id', 'id'], 'crm_stages_owner_id_unique');
            $table->index(['user_id', 'role']);
            $table->index(['user_id', 'position']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('crm_stage_id')->nullable()->after('stage');
            $table->foreign(['user_id', 'crm_stage_id'], 'deals_user_stage_fk')
                ->references(['user_id', 'id'])
                ->on('crm_stages')
                ->restrictOnDelete();
            // Denormalized from crm_stages.role, kept in sync on every write
            // that touches crm_stage_id (DealBoard::move(), the automation,
            // stage rename/delete-with-reassign) — see Deal::scopeOpen() and
            // DealBoard's role-based filters for why a join per row on the
            // board's main list query is worth avoiding. Left nullable
            // rather than tightened, the same permanent choice already made
            // for crm_workspace_id on this same table: the guarantee lives
            // in the application (every write path sets it), not a DB
            // constraint that would have to survive a transient backfill
            // window.
            $table->string('stage_role', 16)->nullable()->after('crm_stage_id');
            $table->index(['user_id', 'stage_role']);
        });

        // Every existing owner gets the pipeline they already had, named so
        // it keeps translating: new_lead/haggling/payment/cancelled are both
        // the stored `name` (a translation key while is_system stays true)
        // and the exact strings every existing `deals.stage` row already
        // holds, so the second pass below is a plain join on that string.
        DB::table('users')->select('id')->orderBy('id')->chunkById(200, function ($users) {
            $now = now();

            foreach ($users as $user) {
                $stageIds = [];

                foreach ([
                    ['name' => 'new_lead', 'role' => 'initial', 'position' => 0, 'color' => 'slate'],
                    ['name' => 'haggling', 'role' => null, 'position' => 1, 'color' => 'amber'],
                    ['name' => 'payment', 'role' => 'won', 'position' => 2, 'color' => 'green'],
                    ['name' => 'cancelled', 'role' => 'lost', 'position' => null, 'color' => 'rose'],
                ] as $stage) {
                    $stageIds[$stage['name']] = DB::table('crm_stages')->insertGetId([
                        'user_id' => $user->id,
                        'name' => $stage['name'],
                        'role' => $stage['role'],
                        'position' => $stage['position'],
                        'color' => $stage['color'],
                        'is_system' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($stageIds as $stageName => $stageId) {
                    DB::table('deals')
                        ->where('user_id', $user->id)
                        ->where('stage', $stageName)
                        ->update([
                            'crm_stage_id' => $stageId,
                            'stage_role' => match ($stageName) {
                                'new_lead' => 'initial',
                                'payment' => 'won',
                                'cancelled' => 'lost',
                                default => null,
                            },
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropForeign('deals_user_stage_fk');
            $table->dropIndex(['user_id', 'stage_role']);
            $table->dropColumn(['crm_stage_id', 'stage_role']);
        });

        Schema::dropIfExists('crm_stages');
    }
};
