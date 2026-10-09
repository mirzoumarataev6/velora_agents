<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('crm_workspace_id')->nullable()->after('user_id');
            $table->foreign(['user_id', 'crm_workspace_id'], 'customers_user_workspace_fk')
                ->references(['user_id', 'id'])->on('crm_workspaces')->restrictOnDelete();
            $table->index(['user_id', 'crm_workspace_id', 'last_contact_at'], 'customers_workspace_contact_idx');
        });

        DB::table('users')->select('id')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                if (! DB::table('customers')->where('user_id', $user->id)->exists()) {
                    continue;
                }

                DB::table('crm_workspaces')->insertOrIgnore([
                    'user_id' => $user->id,
                    'name' => '__main__',
                    'color' => 'blue',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $workspaceId = DB::table('crm_workspaces')
                    ->where('user_id', $user->id)
                    ->where('name', '__main__')
                    ->value('id');

                DB::table('customers')->where('user_id', $user->id)
                    ->update(['crm_workspace_id' => $workspaceId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign('customers_user_workspace_fk');
            $table->dropIndex('customers_workspace_contact_idx');
            $table->dropColumn('crm_workspace_id');
        });
    }
};
