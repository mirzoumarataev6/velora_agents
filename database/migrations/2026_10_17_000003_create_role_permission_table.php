<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            // A permission KEY from the fixed, code-defined catalog
            // (App\Services\Permissions\PermissionCatalog), validated there
            // at write time — never a free string. Deliberately not a
            // foreign key to a `permissions` table: the catalog never varies
            // per tenant, so there is nothing tenant-editable to protect here
            // except which of its keys a role holds, which is this row.
            $table->string('permission');
            $table->timestamps();

            $table->unique(['role_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
