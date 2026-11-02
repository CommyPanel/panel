<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_databases', function (Blueprint $table) {
            // Set while a dump is being loaded into a new database: restoring, or failed with the reason.
            $table->string('restore_status', 16)->nullable();
            $table->string('restore_from')->nullable();
            $table->text('restore_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('managed_databases', function (Blueprint $table) {
            $table->dropColumn(['restore_status', 'restore_from', 'restore_error']);
        });
    }
};
