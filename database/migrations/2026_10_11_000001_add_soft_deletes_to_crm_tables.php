<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deals, customers, tasks and workspaces become recoverable instead of gone
 * the instant someone clicks delete.
 *
 * `deal_activities.deal_id` still cascades on a REAL delete — that constraint
 * is untouched here, deliberately. Soft-deleting a Deal now never issues that
 * DELETE at all (SoftDeletes turns it into an UPDATE of deleted_at), so the
 * cascade simply never fires and the activity history survives a soft-delete
 * for free; it only ever fires once something is force-deleted for real,
 * which is exactly when losing the history alongside it is correct.
 *
 * `customers`' own unique index on (user_id, phone_key) is NOT changed here —
 * see Customer::booted() for why a column-level guarantee (phone_key is
 * always null on a soft-deleted row) is the fix, not a wider index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('crm_workspaces', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('crm_workspaces', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
