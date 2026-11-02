<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            // Names the container (db-<slug>), its network and its data folder.
            $table->string('slug', 30)->unique();
            $table->string('engine', 16);
            $table->string('version', 16);
            $table->string('status', 16)->default('provisioning');
            $table->text('error')->nullable();
            $table->text('root_password');
            $table->unsignedInteger('memory_limit_mb')->nullable();
            $table->timestamps();
        });

        Schema::create('managed_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 31);
            $table->string('username', 31);
            $table->text('password');
            $table->timestamps();

            $table->unique(['database_server_id', 'name']);
            $table->unique(['database_server_id', 'username']);
        });

        Schema::create('app_database_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('managed_database_id')->constrained()->cascadeOnDelete();
            // The names of the environment variables that carry the connection: DATABASE_URL, DATABASE_HOST, ...
            $table->string('env_prefix', 32);
            $table->timestamps();

            $table->unique(['app_id', 'managed_database_id']);
            $table->unique(['app_id', 'env_prefix']);
        });

        Schema::create('app_links', function (Blueprint $table) {
            $table->id();
            // The app that may reach the other one.
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('env_prefix', 32);
            $table->timestamps();

            $table->unique(['app_id', 'target_app_id']);
            $table->unique(['app_id', 'env_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_links');
        Schema::dropIfExists('app_database_links');
        Schema::dropIfExists('managed_databases');
        Schema::dropIfExists('database_servers');
    }
};
