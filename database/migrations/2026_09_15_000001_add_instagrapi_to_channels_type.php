<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->enum('type', ['telegram', 'whatsapp', 'instagram', 'greenapi', 'instagrapi'])->change();
        });
    }

    public function down(): void
    {
        DB::table('channels')->where('type', 'instagrapi')->delete();

        Schema::table('channels', function (Blueprint $table) {
            $table->enum('type', ['telegram', 'whatsapp', 'instagram', 'greenapi'])->change();
        });
    }
};
