<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->date('deal_date')->nullable()->after('amount');
            $table->text('cancelled_reason')->nullable()->after('closed_at');
            $table->index(['user_id', 'deal_date']);
        });

        // Preserve the owner's existing effective deal dates while restoring
        // created_at to its actual database-record meaning going forward.
        DB::table('deals')->whereNull('deal_date')->update([
            'deal_date' => DB::raw('DATE(created_at)'),
        ]);
        DB::table('deals')->where('stage', 'payment')->update(['payment_status' => 'paid']);

        Schema::create('deal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['deal_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::table('deals')->select('id', 'user_id', 'created_at')->orderBy('id')->chunkById(500, function ($deals) {
            DB::table('deal_activities')->insert($deals->map(fn ($deal) => [
                'user_id' => $deal->user_id,
                'deal_id' => $deal->id,
                'type' => 'created',
                'data' => null,
                'created_at' => $deal->created_at,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_activities');

        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'deal_date']);
            $table->dropColumn(['deal_date', 'cancelled_reason']);
        });
    }
};
