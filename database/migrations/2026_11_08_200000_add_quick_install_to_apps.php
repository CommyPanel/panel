<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // A quick-install app (WordPress, ...): which one, and what the person filled in that is not secret.
            $table->string('template', 32)->nullable();
            $table->json('template_settings')->nullable();
            // The database server chosen in the wizard; the database itself is made when setup finishes.
            $table->unsignedBigInteger('template_database_server_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['template', 'template_settings', 'template_database_server_id']);
        });
    }
};
