<?php

use App\Services\Leads\PhoneNumberDetector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated, nullable phone column for leads.
 *
 * Separate from `contact`, which is free text and holds whatever the customer
 * offered — an @username, an email, "позвоните вечером". Two things now turn on
 * knowing whether the business can actually RING this person: what the
 * assistant promises them ("мы вам позвоним" vs "свяжемся здесь, в чате"), and
 * the "только чат" flag in the owner's panel. Neither can be decided by pattern
 * matching a free-text column at render time.
 *
 * Nullable on purpose and by requirement: an order the customer declined to
 * leave a number for is still an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('customer_name');
        });

        // Existing leads keep their number: without this every order taken
        // before today would show as "только чат" in the panel, which is a
        // false alarm on data that is perfectly callable.
        $detector = new PhoneNumberDetector;

        DB::table('leads')
            ->whereNotNull('contact')
            ->orderBy('id')
            ->chunk(500, function ($leads) use ($detector) {
                foreach ($leads as $lead) {
                    $phone = $detector->find($lead->contact);

                    if ($phone !== null) {
                        DB::table('leads')->where('id', $lead->id)->update(['phone' => $phone]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
