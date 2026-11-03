<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // "owner/name" when the code is read through the panel's GitHub App.
            $table->string('github_repo', 140)->nullable()->index()->after('git_token_username');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropIndex(['github_repo']);
            $table->dropColumn('github_repo');
        });
    }
};
