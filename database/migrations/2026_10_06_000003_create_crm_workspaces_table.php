<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The reserved name keeps the system folder unique per owner
            // without a partial unique index with different engine semantics.
            $table->string('name', 80);
            $table->string('color', 16)->default('blue');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->unique(['user_id', 'id'], 'crm_workspaces_owner_id_unique');
            $table->index(['user_id', 'is_default']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('crm_workspace_id')->nullable()->after('user_id');
            // A plain FK to workspace.id would let a buggy caller attach one
            // owner's deal to another owner's folder. The composite reference
            // makes that impossible in the database as well as in validation.
            $table->foreign(['user_id', 'crm_workspace_id'], 'deals_user_workspace_fk')
                ->references(['user_id', 'id'])
                ->on('crm_workspaces')
                ->restrictOnDelete();
            $table->index(['user_id', 'crm_workspace_id', 'sales_origin', 'deal_date'], 'deals_crm_workspace_origin_date_idx');
        });

        // Existing deals must remain visible after the folder filter is added.
        // A reserved internal name lets the UI translate this system folder for
        // each owner without baking one locale into migrated data.
        DB::table('users')->select('id')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                if (! DB::table('deals')->where('user_id', $user->id)->exists()) {
                    continue;
                }

                $workspaceId = DB::table('crm_workspaces')->insertGetId([
                    'user_id' => $user->id,
                    'name' => '__main__',
                    'color' => 'blue',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('deals')->where('user_id', $user->id)->update(['crm_workspace_id' => $workspaceId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropForeign('deals_user_workspace_fk');
            $table->dropIndex('deals_crm_workspace_origin_date_idx');
            $table->dropColumn('crm_workspace_id');
        });

        Schema::dropIfExists('crm_workspaces');
    }
};
