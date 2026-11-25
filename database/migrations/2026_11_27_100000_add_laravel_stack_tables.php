<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Per-stack choices (Laravel: php version, node package manager, ...).
            $table->json('stack_options')->nullable();
            // What the wizard found by looking at the code (Laravel: version, services, suggested volumes).
            $table->json('detected')->nullable();
            // The whole .env text of a Laravel app, comments and order included (encrypted). The variables are
            // derived from it every time it is saved.
            $table->text('env_text')->nullable();
            // A script run before a release goes live (Laravel: php artisan migrate --force), several lines.
            $table->text('release_script')->nullable();
        });

        Schema::table('deployments', function (Blueprint $table) {
            // The script this release ran, copied when it was queued.
            $table->text('release_script')->nullable();
            // The containers of this release other than the web one: [{name, container, kind, overlap, replicas}].
            $table->json('processes')->nullable();
        });

        Schema::create('app_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            // The web process is the app itself and has no row. Names are [a-z][a-z0-9-]{0,30}.
            $table->string('name', 31);
            $table->string('kind', 16); // scheduler, queue, horizon, pulse, ssr, custom
            $table->text('command')->nullable(); // null: the default command of the kind
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('replicas')->default(1);
            $table->string('overlap', 16)->default('overlap'); // overlap: may run beside the old release; stop_first
            $table->unsignedInteger('memory_limit_mb')->nullable();
            $table->unsignedSmallInteger('stop_grace_seconds')->default(30);
            $table->string('detected_by')->nullable(); // for example laravel/horizon
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['app_id', 'name']);
        });

        Schema::table('database_servers', function (Blueprint $table) {
            // A server made for one app, whose user may create and drop databases (multi-database, tenancy).
            $table->foreignId('dedicated_app_id')->nullable()->constrained('apps')->nullOnDelete();
        });

        Schema::table('app_database_links', function (Blueprint $table) {
            // prefix: <PREFIX>_URL, _HOST, ... ; laravel: DB_CONNECTION, DB_HOST, DB_DATABASE, DB_USERNAME, ...
            $table->string('env_style', 16)->default('prefix');
        });
    }

    public function down(): void
    {
        Schema::table('app_database_links', function (Blueprint $table) {
            $table->dropColumn('env_style');
        });
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dedicated_app_id');
        });
        Schema::dropIfExists('app_processes');
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['release_script', 'processes']);
        });
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['stack_options', 'detected', 'env_text', 'release_script']);
        });
    }
};
