<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Runs once per deployment from the new image, before it goes live (e.g. migrations).
            $table->string('release_command', 1000)->nullable()->after('health_path');
            // Set while the live container is stopped by the user; the release stays live.
            $table->timestamp('stopped_at')->nullable()->after('active_deployment_id');
        });

        Schema::table('deployments', function (Blueprint $table) {
            // The release command as it was when the deployment was queued (null: none, or a rollback).
            $table->string('release_command', 1000)->nullable()->after('commit_message');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['release_command', 'stopped_at']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('release_command');
        });
    }
};
