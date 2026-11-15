<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // A quick-install app (Meilisearch, Grafana, ...): which recipe, and what is not secret of what was chosen.
            $table->string('template', 32)->nullable();
            $table->json('template_settings')->nullable();
        });

        // 0.8.2 added this column on its own (a setting on every app); skip it when it is there.
        if (! Schema::hasColumn('apps', 'health_timeout')) {
            Schema::table('apps', function (Blueprint $table) {
                $table->unsignedSmallInteger('health_timeout')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['template', 'template_settings']);
        });
    }
};
