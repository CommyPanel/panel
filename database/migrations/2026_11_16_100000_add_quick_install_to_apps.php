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
            // Slow starters (Elasticsearch, Kibana) get longer than the global health timeout.
            $table->unsignedSmallInteger('health_timeout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['template', 'template_settings', 'health_timeout']);
        });
    }
};
