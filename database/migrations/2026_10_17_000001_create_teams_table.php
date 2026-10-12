<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Audit only — who created the row, not who is authorized to act
            // on it. That is `team_user`'s job.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Billing gates (see the permission catalog) exist before any
            // billing UI does; these columns are placeholders for whenever
            // that UI is built, not read by anything yet.
            $table->string('billing_plan')->nullable();
            $table->string('billing_customer_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
