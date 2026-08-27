<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which product source `search_products` reads from: the uploaded catalog
     * ('dataset') or the customer's own database ('database').
     *
     * Explicitly one or the other, never both. Two live sources would make the
     * agent's answer depend on which one it happened to consult, and an owner
     * who uploaded a file months ago has no way to know a stale copy is still
     * being quoted alongside their live stock.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('product_source', 20)->default('dataset')->after('transcribe_voice');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('product_source');
        });
    }
};
