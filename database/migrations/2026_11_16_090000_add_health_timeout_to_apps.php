<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded: a later release adds the same column in another migration.
        if (Schema::hasColumn('apps', 'health_timeout')) {
            return;
        }

        Schema::table('apps', function (Blueprint $table) {
            // Seconds a new version gets to answer its health check; null uses DEPLOY_HEALTH_TIMEOUT.
            $table->unsignedSmallInteger('health_timeout')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('apps', 'health_timeout')) {
            Schema::table('apps', function (Blueprint $table) {
                $table->dropColumn('health_timeout');
            });
        }
    }
};
