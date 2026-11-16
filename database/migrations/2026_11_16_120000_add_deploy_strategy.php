<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // rolling (the new version starts beside the old one) or stop_first (the old one stops first).
            $table->string('deploy_strategy', 16)->default('rolling');
        });

        Schema::table('deployments', function (Blueprint $table) {
            // What this deployment used, copied when it was queued, so a later change of the app does not rewrite history.
            $table->string('strategy', 16)->default('rolling');
        });

        // Ready-made apps that cannot run twice on one data folder: their deploys could never succeed side by side.
        DB::table('apps')->whereIn('template', ['redis', 'garage', 'elasticsearch'])->update(['deploy_strategy' => 'stop_first']);
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('strategy');
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('deploy_strategy');
        });
    }
};
