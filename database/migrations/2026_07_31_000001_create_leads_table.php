<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            // Nullable + nullOnDelete so a lead survives its conversation being
            // pruned — the customer's contact details are worth keeping even if
            // the chat history is gone.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('contact')->nullable();
            $table->text('items')->nullable();
            $table->text('note')->nullable();
            // Channel type the lead came in through ('telegram', 'whatsapp',
            // 'instagram', 'playground'), for at-a-glance triage in the list.
            $table->string('source')->nullable();
            $table->enum('status', ['new', 'in_progress', 'done'])->default('new');
            // Distinct from status: drives the unread badge on the in-app bell.
            // A lead can be marked "seen" (badge cleared) while still being 'new'
            // in the owner's workflow.
            $table->timestamp('seen_at')->nullable();
            // Raw arguments the model passed to create_lead, kept verbatim so no
            // captured detail is lost to the normalized columns above.
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['agent_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
