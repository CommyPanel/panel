<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_volumes', function (Blueprint $table) {
            // Archived by the nightly backup run when on.
            $table->boolean('nightly_backup')->default(false);
        });

        Schema::table('managed_databases', function (Blueprint $table) {
            // What happened to the last restore over this database that failed but left it as it was.
            $table->text('restore_notice')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_volumes', function (Blueprint $table) {
            $table->dropColumn('nightly_backup');
        });

        Schema::table('managed_databases', function (Blueprint $table) {
            $table->dropColumn('restore_notice');
        });
    }
};
