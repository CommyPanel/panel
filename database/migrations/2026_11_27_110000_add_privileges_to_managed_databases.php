<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_databases', function (Blueprint $table) {
            // What the database's user may do when a person changed it: {privileges: [...], grant_option: bool}.
            // Null means the default for the kind of user (see UserPrivileges).
            $table->json('privileges')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('managed_databases', function (Blueprint $table) {
            $table->dropColumn('privileges');
        });
    }
};
