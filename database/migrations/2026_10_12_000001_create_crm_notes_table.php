<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The timestamped, authored thread that replaces the single freeform `note`
 * textarea on deals and customers — and doubles as a manual activity log
 * (the owner logging a call, a visit, a meeting) rather than just free text.
 *
 * Polymorphic over Deal and Customer rather than two separate tables: both
 * subjects want the exact same shape (kind, body, who, when), and a future
 * third notable type costs a migration instead of a whole new feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->string('notable_type');
            $table->unsignedBigInteger('notable_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('note');
            $table->text('body');
            // Defaults to now() at write time (set by the controller, not the
            // column) — this lets the owner log something that happened
            // earlier without it being misread as "created at" for sorting.
            $table->timestamp('happened_at');
            $table->timestamps();
            $table->softDeletes();

            // The only query this table is read with: a subject's thread,
            // newest first.
            $table->index(['notable_type', 'notable_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
    }
};
