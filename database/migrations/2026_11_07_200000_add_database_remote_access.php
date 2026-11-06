<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            // Set once the server runs with TLS (needed before it can be reached from outside).
            $table->boolean('tls_enabled')->default(false);
            // The certificate authority's public certificate, which people give their clients.
            $table->text('tls_ca')->nullable();
            // Remote access: the public port is open while remote_port is set.
            $table->unsignedInteger('remote_port')->nullable();
            // opening (waiting for the server to restart with TLS), open, failed
            $table->string('remote_status', 16)->nullable();
            $table->string('remote_error', 300)->nullable();
            $table->json('remote_allow')->nullable();
            $table->timestamp('remote_expires_at')->nullable();
            $table->boolean('remote_forever')->default(false);
            $table->timestamp('remote_opened_at')->nullable();
            $table->string('remote_opened_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn(['tls_enabled', 'tls_ca', 'remote_port', 'remote_status', 'remote_error', 'remote_allow', 'remote_expires_at', 'remote_forever', 'remote_opened_at', 'remote_opened_by']);
        });
    }
};
