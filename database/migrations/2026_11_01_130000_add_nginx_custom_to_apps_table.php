<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Extra nginx directives for the app's site, as the user typed them (comments included).
            // The agent checks them against an allow-list before they reach the config.
            $table->text('nginx_custom')->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('nginx_custom');
        });
    }
};
