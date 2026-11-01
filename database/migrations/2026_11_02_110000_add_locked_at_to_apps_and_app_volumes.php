<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A locked app cannot be deleted; a locked volume cannot be removed from its app.
        Schema::table('apps', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable();
        });

        Schema::table('app_volumes', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });

        Schema::table('app_volumes', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};
