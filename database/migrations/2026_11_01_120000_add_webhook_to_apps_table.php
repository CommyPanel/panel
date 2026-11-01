<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Random id in the push webhook's URL (not the slug, so URLs reveal nothing); null = off.
            $table->string('webhook_id', 32)->nullable()->unique()->after('git_token_username');
            // Shared secret the Git host signs pushes with (encrypted).
            $table->text('webhook_secret')->nullable()->after('webhook_id');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique(['webhook_id']);
            $table->dropColumn(['webhook_id', 'webhook_secret']);
        });
    }
};
