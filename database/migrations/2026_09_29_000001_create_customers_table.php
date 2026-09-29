<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per real person, across every channel they have ever written from.
 *
 * WHY PER OWNER, NOT PER AGENT. A conversation belongs to an agent, because an
 * agent is what answered it. A customer does not: the same person writing to
 * the shop's sales bot and its support bot is one customer with two threads,
 * and an owner who had to reconcile two profiles by hand would stop using the
 * CRM by the second week.
 *
 * WHAT MAKES TWO THREADS THE SAME PERSON is `phone_key` and nothing else — see
 * CustomerResolver for why name matching is deliberately not attempted. The key
 * is nullable because most customers never give a number, and those simply stay
 * one profile per channel identity until a number turns up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            // As given, for display and dialling: the customer's own "+992 90
            // 011 22 33" is what the owner recognises.
            $table->string('phone')->nullable();
            // As matched on: the last nine digits, so a number written with a
            // country code and the same number written without one are one
            // customer. Never shown.
            $table->string('phone_key', 16)->nullable();
            // Channel type of the FIRST contact ('telegram', 'whatsapp', ...).
            // Where they came from is a fact about acquisition and does not
            // change when they later write from somewhere else.
            $table->string('source', 32)->nullable();
            // lead -> active -> customer, or lost. A string rather than an enum
            // so the next state added does not need a table rebuild on MySQL.
            $table->string('status', 16)->default('lead');
            $table->json('tags')->nullable();
            $table->timestamp('first_contact_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // The merge invariant: one owner cannot hold two profiles for one
            // number. MySQL allows any number of NULLs here, which is exactly
            // right — unidentified customers must not collide with each other.
            $table->unique(['user_id', 'phone_key']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'last_contact_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
