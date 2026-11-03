<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Off until a person turns it on: a push never deploys an app by surprise.
            $table->boolean('github_auto_deploy')->default(false)->after('github_repo');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('github_auto_deploy');
        });
    }
};
