<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // When variables, volumes or links last changed: the running container predates it.
            $table->timestamp('config_changed_at')->nullable();
        });

        Schema::table('deployments', function (Blueprint $table) {
            // A deployment that only recreates the container from the image that is already live.
            $table->boolean('settings_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $table) => $table->dropColumn('config_changed_at'));
        Schema::table('deployments', fn (Blueprint $table) => $table->dropColumn('settings_only'));
    }
};
