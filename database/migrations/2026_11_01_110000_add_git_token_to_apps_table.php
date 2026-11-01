<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // HTTPS access token for a private repository (encrypted); wins over the deploy key.
            $table->text('git_token')->nullable()->after('deploy_key_public');
            $table->string('git_token_username', 100)->nullable()->after('git_token');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['git_token', 'git_token_username']);
        });
    }
};
