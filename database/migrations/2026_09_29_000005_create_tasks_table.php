<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ring Азиз back on Thursday."
 *
 * Attached to a customer, a deal, both or neither: the reminder that matters
 * most is often the one you write before you know which deal it belongs to, and
 * forcing a link would mean it never gets written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            // Nullable: "someday, when there's time" is a real kind of task, and
            // demanding a date turns the list into a source of fake deadlines.
            $table->timestamp('due_at')->nullable();
            // Done is a TIME, not a flag: when it was finished is worth keeping,
            // and a null is the cheapest possible "still open" index.
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            // Everything the list and the widget ask for: this owner's open
            // tasks, soonest first.
            $table->index(['user_id', 'done_at', 'due_at']);
            $table->index('customer_id');
            $table->index('deal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
